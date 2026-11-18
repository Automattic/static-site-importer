<?php
/**
 * Regression for #3010: preceding field copy must be a visible provider label.
 *
 * @package StaticSiteImporter
 */

// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';
require_once __DIR__ . '/fixtures/form-jetpack-render.php';
require_once dirname( __DIR__ ) . '/vendor/automattic/blocks-engine-php-transformer/php-transformer.php';

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;

$cases = array(
	// Minimal failure: the inner div binds Name as description instead of label.
	'minimal' => '<form><div><p>Name</p><input name="NAME"></div></form>',
	'styled' => '<form><div><p class="caption" style="color:#123456">Name</p><input name="NAME"></div></form>',
	'explicit-label' => '<form><div><label for="n">Full name</label><p>Name</p><input id="n" name="NAME"></div></form>',
	'unbound-copy' => '<form><p>Name</p><input name="NAME"></form>',
);
foreach ( $cases as $name => $html ) {
	$compiled = ( new ArtifactCompiler() )->compile( array( 'entrypoint' => 'index.html', 'files' => array( 'index.html' => $html ) ) )->toArray();
	$fallback = array_values( array_filter( $compiled['fallbacks'], static fn( $f ) => ! empty( $f['controls'] ) ) )[0];
	$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => array( $fallback ) ) );
	$row = Static_Site_Importer_Form_Seeder::seed( array( 'forms' => $validated['forms'] ) )['forms'][0];
	$markup = $row['block_markup'];
	preg_match( '/<!-- wp:jetpack\/label (\{.*?\}) /', $markup, $match );
	$label = json_decode( $match[1] ?? '{}', true );
	$promoted = in_array( $name, array( 'minimal', 'styled' ), true );
	$valid = 'mapped' === $row['status'] && empty( $validated['errors'] );
	if ( $promoted ) {
		$valid = $valid && 'Name' === ( $label['label'] ?? '' ) && false !== ( $label['metadata']['blockVisibility'] ?? null );
		$valid = $valid && ! str_contains( $markup, 'wp:core/paragraph' ) && ! str_contains( $markup, 'helpText' );
		$blocks = ssi_role_box_parse_blocks( $markup );
		$rendered = ssi_role_box_render_form( $blocks[0] );
		$valid = $valid && 1 === preg_match( '/<label\b[^>]*>Name<\/label>/', $rendered );
		if ( 'styled' === $name ) {
			$valid = $valid && str_contains( $label['className'] ?? '', 'caption' ) && ! empty( $label['style'] );
		}
	} elseif ( 'explicit-label' === $name ) {
		$valid = $valid && 'Full name' === ( $label['label'] ?? '' ) && str_contains( $markup, '>Name</p>' );
	} else {
		$valid = $valid && 'NAME' === ( $label['label'] ?? '' ) && false === ( $label['metadata']['blockVisibility'] ?? null ) && str_contains( $markup, '>Name</p>' );
	}
	if ( ! $valid ) {
		fwrite( STDERR, "FAIL $name\n$markup\n" . ( $rendered ?? '' ) );
		exit( 1 );
	}
}
echo "form sibling caption passed\n";
