<?php
/** Provider buttons require core/buttons even inside captured layout shells. */
// phpcs:ignoreFile -- Standalone CLI regression.
require_once __DIR__ . '/fixtures/core-html-api.php';
$wp_root = require __DIR__ . '/fixtures/wordpress-core-root.php';
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( $wp_root );
require_once __DIR__ . '/fixtures/form-seeder-cli-stubs.php';

$result = ( new Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler() )->compile( array(
 'entrypoint' => 'index.html',
 'files' => array( 'index.html' => file_get_contents( __DIR__ . '/fixtures/form-submit-parent.html' ) ),
) )->toArray();
$plan = $result['source_reports']['wordpress_site_plan'];
$declaration = current( array_filter( $plan['runtime_declarations'], static fn( array $row ): bool => 'forms' === ( $row['type'] ?? null ) ) );
$entities = Static_Site_Importer_Entity_Materializer_Registry::with_form_source_html( $declaration['payload']['entities'], $plan );
$validated = Static_Site_Importer_Entity_Materializer_Registry::validate_forms_manifest( array( 'forms' => $entities ) );
$row = Static_Site_Importer_Form_Seeder::seed( $validated )['forms'][0];
$errors = array();
$count = 0;
$shell = false;
$walk = static function( array $blocks, string $parent = '' ) use ( &$walk, &$errors, &$count, &$shell ): void {
 foreach ( $blocks as $block ) {
  if ( str_ends_with( $parent, '/layout-shell' ) && 'core/buttons' === $block['blockName'] ) $shell = true;
  if ( 'core/buttons' === $block['blockName'] ) {
   preg_match( '/\bssi-node-[a-f0-9]{12}-destination-slot\b/', $block['attrs']['className'] ?? '', $slots );
   $slot = $slots[0] ?? '';
   if ( '' === $slot ) $errors[] = 'core/buttons must declare a destination slot';
   $saved = new WP_HTML_Tag_Processor( $block['innerHTML'] );
   if ( ! $saved->next_tag( array( 'tag_name' => 'DIV', 'class_name' => 'wp-block-buttons' ) ) || ! $saved->has_class( $slot ) ) {
    $errors[] = 'Saved wp-block-buttons div must carry its destination slot class';
   }
  }
  if ( 'core/button' === $block['blockName'] ) {
   ++$count;
   if ( 'core/buttons' !== $parent ) $errors[] = 'core/button parent=' . $parent;
  }
  $walk( $block['innerBlocks'], $block['blockName'] );
 }
};
$walk( parse_blocks( $row['block_markup'] ) );
if ( ! $shell ) $errors[] = 'Fixture must exercise buttons inside a layout-shell';
if ( 'mapped' !== $row['status'] || 2 !== $count ) $errors[] = 'Fixture must map both buttons';
echo $row['block_markup'] . "\n";
foreach ( $errors as $error ) fwrite( STDERR, "FAIL: $error\n" );
exit( empty( $errors ) ? 0 : 1 );
