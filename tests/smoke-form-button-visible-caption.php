<?php
/**
 * A source button named apart from what it paints
 * (`<button aria-label="Request email updates">↗</button>`) renders its
 * visible caption and keeps the name for assistive technology. Blocks Engine
 * reports `visible_text` and `accessible_name` for such buttons (`text` stays
 * the accessible name); metadata without them falls back to `text`.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression, using filesystem and output without WordPress.
require_once __DIR__ . '/fixtures/core-html-api.php';
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-provider-form-runtime.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$failures = array();
$check    = static function ( bool $ok, string $name, string $detail = '' ) use ( &$failures ): void {
	if ( ! $ok ) {
		$failures[] = 'FAIL: ' . $name . ( '' !== $detail ? ' - ' . $detail : '' );
	}
};

/**
 * Seed one compiled form, with each button control's metadata adjusted by $adjust.
 *
 * @return array<string,mixed>
 */
$seed = static function ( string $form, callable $adjust ): array {
	$html        = '<!doctype html><html><head></head><body><main>' . $form . '</main></body></html>';
	$plan        = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray()['source_reports']['wordpress_site_plan'];
	$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
	$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan ) ) );
	foreach ( $validated['forms'][0]['controls'] as $index => $control ) {
		if ( 'button' === ( $control['tag'] ?? '' ) ) {
			$validated['forms'][0]['controls'][ $index ] = $adjust( $control );
		}
	}
	return Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
};

$named_apart = static fn( array $control ): array => array_merge( $control, array( 'text' => 'Request email updates', 'visible_text' => '↗', 'accessible_name' => 'Request email updates' ) );
$caption_is_name = static function ( array $control ): array {
	unset( $control['visible_text'], $control['accessible_name'] );
	return array_merge( $control, array( 'text' => 'Send' ) );
};

// A submit (the default button type) named apart from its glyph.
$submit = $seed( '<form><label for="e">Email</label><input id="e" name="email" type="email"><button aria-label="Request email updates">↗</button></form>', $named_apart );
$markup = $submit['block_markup'];
$check( 'mapped' === $submit['status'], 'named submit maps' );
$check( (bool) preg_match( '#<button type="submit" class="wp-block-button__link wp-element-button">↗</button>#u', $markup ), 'submit renders its visible caption', $markup );
$check( ! str_contains( $markup, '>Request email updates<' ), 'submit does not paint its accessible name' );
$check( str_contains( $markup, '"staticSiteImporterAccessibleName":"Request email updates"' ), 'submit records its accessible name' );

// The runtime applies the recorded name to the rendered control.
$block    = array( 'attrs' => array( 'metadata' => array( Static_Site_Importer_Provider_Form_Runtime_V1::ACCESSIBLE_NAME_METADATA => 'Request email updates' ) ) );
$rendered = Static_Site_Importer_Provider_Form_Runtime_V1::project_button_accessible_name( '<div class="wp-block-button"><button type="submit" class="wp-block-button__link">↗</button></div>', $block );
$check( '<div class="wp-block-button"><button type="submit" class="wp-block-button__link" aria-label="Request email updates">↗</button></div>' === $rendered, 'runtime applies the accessible name', $rendered );
$check( '<button aria-label="Kept">↗</button>' === Static_Site_Importer_Provider_Form_Runtime_V1::project_button_accessible_name( '<button aria-label="Kept">↗</button>', $block ), 'runtime keeps an existing aria-label' );
$check( '<button>Send</button>' === Static_Site_Importer_Provider_Form_Runtime_V1::project_button_accessible_name( '<button>Send</button>', array( 'attrs' => array() ) ), 'runtime leaves unnamed buttons alone' );

// A plain (non-submit) button named apart from its glyph.
$plain  = $seed( '<form><label for="n">Name</label><input id="n" name="name" type="text"><label for="m">Email</label><input id="m" name="email" type="email"><button type="button" class="next" aria-label="Next slide">→</button><button type="submit">Go</button></form>', static fn( array $control ): array => 'button' === ( $control['type'] ?? '' ) ? array_merge( $control, array( 'text' => 'Next slide', 'visible_text' => '→', 'accessible_name' => 'Next slide' ) ) : $control );
$check( (bool) preg_match( '#<button type="button" class="wp-block-button__link wp-element-button">→</button>#u', $plain['block_markup'] ), 'plain button renders its visible caption', $plain['block_markup'] );
$check( str_contains( $plain['block_markup'], '"staticSiteImporterAccessibleName":"Next slide"' ), 'plain button records its accessible name' );

// Metadata without the new fields falls back to the control text.
$fallback = $seed( '<form><label for="m">Email</label><input id="m" name="email" type="email"><button type="submit">Send</button></form>', $caption_is_name );
$check( (bool) preg_match( '#<button type="submit" class="wp-block-button__link wp-element-button">Send</button>#', $fallback['block_markup'] ), 'caption falls back to the control text', $fallback['block_markup'] );
$check( ! str_contains( $fallback['block_markup'], 'staticSiteImporterAccessibleName' ), 'no accessible name is recorded when the caption is the name' );

foreach ( $failures as $failure ) {
	fwrite( STDERR, $failure . "\n" );
}
echo empty( $failures ) ? "smoke-form-button-visible-caption: passed\n" : '';
exit( empty( $failures ) ? 0 : 1 );
