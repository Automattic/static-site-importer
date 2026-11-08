<?php
/**
 * A purchase binding completes on both documents that can persist it: a page
 * that owns its product keeps the seeded productId, while the product's own
 * document binds the single-product block to the current product.
 *
 * @package StaticSiteImporter
 */

define( 'ABSPATH', dirname( __DIR__ ) . '/' );
require_once __DIR__ . '/fixtures/core-block-parser.php';
ssi_test_require_core_block_parser( require __DIR__ . '/fixtures/wordpress-core-root.php' );
/**
 * Return the unchanged standalone filter value.
 *
 * @param string $hook Filter name.
 * @param mixed  $value Filter value.
 * @param mixed  ...$args Additional arguments.
 */
function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WordPress stub.
	return $value;
}
/**
 * Encode block attributes.
 *
 * @param mixed $value Value.
 * @param int   $flags Flags.
 */
function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress stub.
}
require_once ABSPATH . 'vendor/autoload.php';
foreach ( array( 'woo-product-seeder', 'product-field-blocks', 'site-plan-persistence' ) as $file ) {
	require_once ABSPATH . 'includes/class-static-site-importer-' . $file . '.php';
}

$fragment = Static_Site_Importer_Woo_Product_Seeder::binding_block_markup( array( 'entity_kind' => 'add_to_cart_form' ), array( 'id' => 17 ) );
$bound    = Static_Site_Importer_Product_Field_Blocks::bind_document( $fragment, array() );
$complete = new ReflectionMethod( Static_Site_Importer_Site_Plan_Persistence::class, 'complete_entity_bindings' );
foreach ( array(
	'page owns product' => array( '<!-- wp:group --><div class="wp-block-group">' . $fragment . '</div><!-- /wp:group -->', 'completed', $fragment ),
	'product document'  => array( '<!-- wp:group --><div class="wp-block-group">' . $bound . '</div><!-- /wp:group -->', 'completed', $bound ),
	'not persisted'     => array( '<!-- wp:paragraph --><p>Source controls</p><!-- /wp:paragraph -->', 'unresolved', $fragment ),
) as $scenario => list( $content, $status, $proof ) ) {
	$state = array(
		'applied' => array(
			'runtime_declarations' => array(
				'entity_bindings' => array(
					array(
						'source_path'              => 'website/store/p1/lamp.html',
						'role'                     => 'commerce_add_to_cart_form',
						'replacement_block_markup' => $fragment,
					),
				),
			),
		),
	);
	$complete->invokeArgs( null, array( &$state, 'website/store/p1/lamp.html', $content, array( 'post_id' => 53 ) ) );
	$binding = $state['applied']['runtime_declarations']['entity_bindings'][0];
	if ( $status !== $binding['status'] || ( 'completed' === $status && hash( 'sha256', $proof ) !== ( $binding['persisted_fragment_hash'] ?? '' ) ) ) {
		fwrite( STDERR, $scenario . ': expected ' . $status . ', got ' . print_r( $binding, true ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPress.PHP.DevelopmentFunctions.error_log_print_r -- CLI assertion.
		exit( 1 );
	}
}
echo "smoke-product-page-binding-proof: ok\n";
