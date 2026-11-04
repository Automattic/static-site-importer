<?php
/**
 * Responsive border variants keep their media scope against seeded fields.
 *
 * A source can scope a field's whole border to a media query. The seeded
 * jetpack/input must then carry no inline border style (an inline declaration
 * beats the media-scoped rule at every width, #2620), the scoped overlay must
 * state the field's unconditional border style — the authored one, or the
 * implicit `none` when the source scopes the border entirely — and a control
 * whose border is not responsive keeps its inline style.
 *
 * Standalone smoke; see test-manifest.json.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$result   = ( new ArtifactCompiler() )->compile( array(
	'entrypoint' => 'index.html',
	'files'      => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-responsive-border.html' ) ),
) )->toArray();
$plan        = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities    = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated   = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$checks      = array(
	'fixture validates into the forms manifest' => empty( $validated['errors'] ) && ! empty( $validated['forms'][0]['controls'] ),
);
$row        = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
$checks['form maps through the provider'] = 'mapped' === $row['status'] && true === ( $row['runtime_mapped'] ?? false );

$flatten = static function ( array $blocks ) use ( &$flatten ): array {
	$out = array();
	foreach ( $blocks as $block ) {
		$out[] = $block;
		$out   = array_merge( $out, $flatten( $block['innerBlocks'] ?? array() ) );
	}
	return $out;
};
$inputs = array();
foreach ( $flatten( parse_blocks( $row['block_markup'] ) ) as $block ) {
	if ( 'jetpack/input' === ( $block['blockName'] ?? '' ) && is_string( $block['attrs']['className'] ?? null ) ) {
		$inputs[ $block['attrs']['className'] ] = $block['attrs'];
	}
}
// Map each seeded field back to its source control through the overlay's
// node class: the class list is the only carrier, keyed by source order.
$node_class = static fn ( int $index ): string => (string) ( array_keys( $inputs )[ $index ] ?? '' );
$classes    = array_keys( $inputs );
$checks['four text fields seed'] = 4 === count( $classes );

$overlay = (string) ( $row['provider_layout_overlay_css']['css'] ?? '' );
$checks['overlay compiles'] = '' !== $overlay;

/**
 * The overlay rules scoped to one field's node class.
 *
 * @param string $class The field's node class list.
 * @return array<int,string> Unconditional rule, then each conditional rule.
 */
$field_rules = static function ( string $class ) use ( $overlay ): array {
	$rules = array();
	if ( 1 !== preg_match( '/ssi-node-[0-9a-f]{12}/', $class, $match ) ) {
		return $rules;
	}
	foreach ( explode( "\n", $overlay ) as $line ) {
		if ( str_contains( $line, $match[0] ) ) {
			$rules[] = $line;
		}
	}
	return $rules;
};
$unconditional = static fn ( array $rules ): string => (string) current(
	array_values(
		array_filter(
			$rules,
			static fn ( string $rule ): bool => ! str_starts_with( $rule, '@media' )
		)
	)
);
$media_1280 = static fn ( array $rules ): string => (string) current(
	array_values(
		array_filter(
			$rules,
			static fn ( string $rule ): bool => str_starts_with( $rule, '@media (min-width: 1280px)' )
		)
	)
);

// The two source fields the issue reduces to (#2620): `.field.boxed`, whose
// border lives in the 1280px media query alone. No inline border style may
// ride the block attribute, and the scoped CSS owns both widths: the source's
// unconditional `none` outside the query, the styled border inside it.
foreach ( array( 0, 1 ) as $boxed ) {
	$class = $node_class( $boxed );
	$rules = $field_rules( $class );
	$checks[ "boxed field {$boxed} carries no inline border style" ] = isset( $inputs[ $class ] ) && ! isset( $inputs[ $class ]['style']['border']['style'] );
	$checks[ "boxed field {$boxed} keeps the source unconditional border-style none" ] = str_contains( $unconditional( $rules ), 'border-style:none' );
	$media = $media_1280( $rules );
	$checks[ "boxed field {$boxed} paints the 1280px solid border" ] = str_contains( $media, 'border-style:solid' ) && str_contains( $media, 'border-width:1px' ) && str_contains( $media, 'border-color:rgba(255, 255, 255, 0.7)' );
}

// The variant-only field (`.boxed` with no unconditional rule of its own)
// proves the scoped CSS owns the whole border instead of the inline attribute:
// the block carries no inline style, the provider default is released to the
// browser default the source shows below the breakpoint, and the field joins
// the 1280px patch.
$topic_class = $node_class( 3 );
$topic_rules = $field_rules( $topic_class );
$checks['variant-only field carries no inline border style'] = isset( $inputs[ $topic_class ] ) && ! isset( $inputs[ $topic_class ]['style']['border']['style'] );
$checks['variant-only field releases the provider border unconditionally'] = str_contains( $unconditional( $topic_rules ), 'border:revert' ) && ! str_contains( $unconditional( $topic_rules ), 'border-style:' );
$checks['variant-only field paints the 1280px solid border'] = str_contains( $media_1280( $topic_rules ), 'border-style:solid' );

// A control whose border is not responsive is unchanged: the unconditional
// dashed border rides the block attribute exactly as before.
$notes_class = $node_class( 2 );
$notes_rules = $field_rules( $notes_class );
$checks['non-responsive field keeps its inline dashed border'] = 'dashed' === ( $inputs[ $notes_class ]['style']['border']['style'] ?? '' );
$checks['non-responsive field keeps the authored unconditional border-style'] = str_contains( $unconditional( $notes_rules ), 'border-style:dashed' );
$checks['non-responsive field gains no 1280px border patch'] = '' === $media_1280( $notes_rules );

$failures = array_keys( array_filter( $checks, static fn( bool $ok ): bool => ! $ok ) );
foreach ( $failures as $failure ) {
	fwrite( STDERR, 'FAIL: ' . $failure . "\n" );
}
echo count( $checks ) . ' checks, ' . count( $failures ) . " failures\n";
exit( empty( $failures ) ? 0 : 1 );
