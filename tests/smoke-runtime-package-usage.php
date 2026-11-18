<?php
/**
 * Generated theme runtimes ship only what the imported site uses (#3108).
 *
 * Compiles small source pages through ArtifactCompiler, resolves their
 * WordPress site plans, and scopes the theme runtime payload exactly as the
 * import path does before packaging it. Optional runtimes ship only through
 * declarations: the payload's `runtime_features`, a generated block's own
 * `runtime_features`, and the selected provider adapters' `runtime_features`.
 *
 * Run: php tests/smoke-runtime-package-usage.php
 *
 * @package StaticSiteImporter
 */

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- CLI assertion failures, not browser output.

use Automattic\BlocksEngine\PhpTransformer\ArtifactCompiler\ArtifactCompiler;
use Automattic\BlocksEngine\PhpTransformer\WordPressSitePlan\WordPressSitePlanResolver;

require dirname( __DIR__ ) . '/vendor/autoload.php';
// Reuse the companion scaffolder's standalone WordPress doubles.
define( 'SSI_COMPANION_DOUBLES_ONLY', true );
require __DIR__ . '/smoke-companion-plugin.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-generated-runtime-package.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-entity-materializer-registry.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-form-seeder.php';
require_once dirname( __DIR__ ) . '/includes/class-static-site-importer-woo-product-seeder.php';

$failures = array();
$assert   = static function ( bool $condition, string $message ) use ( &$failures ): void {
	if ( ! $condition ) {
		$failures[] = $message;
	}
};

$compiled = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<html><head><title>Photography</title></head><body><header><a href="index.html">Studio</a></header><main><h1>Portfolio</h1><p>Weddings and portraits.</p></main><footer>Contact the studio</footer></body></html>',
		),
	)
)->toArray();
$plan     = $compiled['source_reports']['wordpress_site_plan'];
$resolved = ( new WordPressSitePlanResolver() )->resolve( $plan, array( 'theme_uri' => 'https://example.test/wp-content/themes/photo-studio' ) );

// The producer declared runtime blocks the page never uses, as in the issue.
$declared_block = static fn ( string $name ): array => array(
	'name'       => $name,
	'block_json' => array(
		'apiVersion' => 3,
		'name'       => 'ssi-photo-studio/' . $name,
		'title'      => $name,
	),
	'render'     => '<div class="' . $name . '"></div>',
);
$payload        = array(
	'schema'           => Static_Site_Importer_Companion_Plugin::PAYLOAD_SCHEMA,
	'site_slug'        => 'photo-studio',
	'site_name'        => 'Photo studio',
	'blocks'           => array( $declared_block( 'motion-sequence' ), $declared_block( 'live-clock' ) ),
	'runtime_features' => array(),
);

$optional = array(
	'ssi-runtime/includes/provider-form-runtime-v1.php',
	'ssi-runtime/includes/internal-link-runtime.php',
	'ssi-runtime/includes/external-metric-runtime.php',
	'ssi-runtime/includes/ip-classifier.php',
	'ssi-runtime/includes/product-grid-runtime.php',
	'ssi-runtime/includes/product-listing-runtime.php',
	'ssi-runtime/includes/post-source-route-runtime.php',
	'ssi-runtime/editor/external-metric-controls.js',
);

// A site without forms, products, metrics, routes or runtime blocks.
$scoped  = Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $payload, array( $resolved ), array() );
$package = Static_Site_Importer_Generated_Runtime_Package::theme( $scoped, 'photo-studio' );
$assert( ! is_wp_error( $package ), 'unused-runtime-site-packages' );
$shipped = is_wp_error( $package ) ? array() : Static_Site_Importer_Generated_Runtime_Package::shipped_files( $package );
$runtime = is_wp_error( $package ) ? '' : $package['files']['ssi-runtime/runtime.php'];
$assert( array() === array_intersect( $optional, $shipped ), 'unused-site-ships-no-optional-runtime: ' . implode( ', ', array_intersect( $optional, $shipped ) ) );
$assert( ! str_contains( $runtime, "/includes/" ), 'unused-site-runtime-requires-no-optional-include' );
$assert( array() === preg_grep( '~^ssi-runtime/blocks/~', $shipped ), 'unused-site-ships-no-unused-blocks' );
$assert( ! is_wp_error( $package ) && array() === $package['block_names'], 'unused-site-registers-no-blocks' );
$assert( array( 'ssi-runtime/README.md', 'ssi-runtime/companion.json', 'ssi-runtime/editor/imported-media-replace.js', 'ssi-runtime/runtime.php' ) === $shipped, 'shipped-files-counter: ' . implode( ', ', $shipped ) );
$config = is_wp_error( $package ) ? array() : json_decode( $package['files']['ssi-runtime/companion.json'], true );
$assert( array() === ( $config['block_directories'] ?? null ), 'unused-site-config-declares-no-block-directories' );
// Same-request runtime loading stays valid with the scoped include set.
$lint         = array();
$lint_status  = 1;
$runtime_file = sys_get_temp_dir() . '/ssi-runtime-usage-' . getmypid() . '.php';
file_put_contents( $runtime_file, $runtime );
exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $runtime_file ) . ' 2>&1', $lint, $lint_status );
unlink( $runtime_file );
$assert( 0 === $lint_status, 'scoped-runtime-is-valid-php' );

// Declarations ship exactly the runtimes they name: the payload producer
// declares interactions, a selected provider adapter declares commerce, and a
// referenced generated block ships with its own declaration.
$used                                       = $resolved;
$used['pages'][0]['resolved_block_markup'] .= '<!-- wp:ssi-photo-studio/live-clock /-->';
$declaring                                  = $payload;
$declaring['runtime_features']              = array( 'interactions' );
$declaring['blocks'][1]['runtime_features'] = array( 'commerce' );
$used_package                               = Static_Site_Importer_Generated_Runtime_Package::theme( Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $declaring, array( $used ), array() ), 'photo-studio' );
$used_files                                 = is_wp_error( $used_package ) ? array() : Static_Site_Importer_Generated_Runtime_Package::shipped_files( $used_package );
$assert( in_array( 'ssi-runtime/includes/internal-link-runtime.php', $used_files, true ) && in_array( 'ssi-runtime/includes/product-grid-runtime.php', $used_files, true ) && in_array( 'ssi-runtime/includes/product-listing-runtime.php', $used_files, true ), 'declared-features-ship-their-runtime' );
$assert( ! in_array( 'ssi-runtime/includes/provider-form-runtime-v1.php', $used_files, true ) && ! in_array( 'ssi-runtime/includes/external-metric-runtime.php', $used_files, true ), 'undeclared-features-stay-out-beside-declared-ones' );
$assert( in_array( 'ssi-runtime/blocks/live-clock/block.json', $used_files, true ) && ! in_array( 'ssi-runtime/blocks/motion-sequence/block.json', $used_files, true ), 'only-referenced-blocks-ship' );
$used_runtime = is_wp_error( $used_package ) ? '' : $used_package['files']['ssi-runtime/runtime.php'];
$assert( str_contains( $used_runtime, "require_once __DIR__ . '/includes/internal-link-runtime.php';" ) && str_contains( $used_runtime, "require_once __DIR__ . '/includes/product-listing-runtime.php';" ) && ! str_contains( $used_runtime, 'provider-form-runtime-v1.php' ), 'runtime-requires-exactly-shipped-includes' );

// A dropped generated block's declaration does not ship its runtime.
$dropped_package = Static_Site_Importer_Generated_Runtime_Package::theme( Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $declaring, array( $resolved ), array() ), 'photo-studio' );
$assert( ! is_wp_error( $dropped_package ) && ! isset( $dropped_package['files']['ssi-runtime/includes/product-grid-runtime.php'] ), 'dropped-block-declaration-ships-nothing' );

// Marker text without a declaration ships nothing: content is never sniffed.
$marked                                       = $resolved;
$marked['pages'][0]['resolved_block_markup'] .= '<!-- wp:tabs --><div class="wp-block-tabs woocommerce grunion-field-wrap"></div><!-- /wp:tabs --><!-- wp:jetpack/contact-form /--><a data-blocks-engine-capture-member-login="wix">Log in</a>';
$marked_package                               = Static_Site_Importer_Generated_Runtime_Package::theme( Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $payload, array( $marked ), array() ), 'photo-studio' );
$marked_files                                 = is_wp_error( $marked_package ) ? array( 'error' ) : Static_Site_Importer_Generated_Runtime_Package::shipped_files( $marked_package );
$assert( array() === array_intersect( $optional, $marked_files ), 'marker-text-without-declaration-ships-no-runtime: ' . implode( ', ', array_intersect( $optional, $marked_files ) ) );

// A form-bearing source page: the compiler declares a form runtime
// declaration, the Jetpack form adapter it selects declares the forms
// runtime, and the package ships the form runtime through that declaration.
$form_compiled = ( new ArtifactCompiler() )->compile(
	array(
		'entrypoint' => 'index.html',
		'files'      => array(
			'index.html' => '<html><head><title>Contact</title></head><body><main><h1>Book a session</h1><form><label>Email <input name="email" type="email"></label><button type="submit">Send</button></form></main></body></html>',
		),
	)
)->toArray();
$form_plan      = $form_compiled['source_reports']['wordpress_site_plan'];
$form_resolved  = ( new WordPressSitePlanResolver() )->resolve( $form_plan, array( 'theme_uri' => 'https://example.test/wp-content/themes/photo-studio' ) );
$form_lifecycle = Static_Site_Importer_Entity_Materializer_Registry::plan_runtime_lifecycle( $form_plan, array( 'runtime_lifecycle_phase' => 'prepare' ) );
$assert( ! is_wp_error( $form_lifecycle ) && ! empty( $form_lifecycle['dependencies'] ), 'form-fixture-selects-a-form-provider' );
$form_lifecycle = is_wp_error( $form_lifecycle ) ? array() : $form_lifecycle;
$form_package   = Static_Site_Importer_Generated_Runtime_Package::theme( Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $payload, array( $form_resolved ), $form_lifecycle ), 'photo-studio' );
$form_files     = is_wp_error( $form_package ) ? array() : Static_Site_Importer_Generated_Runtime_Package::shipped_files( $form_package );
$form_runtime   = is_wp_error( $form_package ) ? '' : $form_package['files']['ssi-runtime/runtime.php'];
$assert( in_array( 'ssi-runtime/includes/provider-form-runtime-v1.php', $form_files, true ) && str_contains( $form_runtime, "require_once __DIR__ . '/includes/provider-form-runtime-v1.php';" ), 'form-fixture-ships-form-runtime-through-declaration' );
$assert( ! in_array( 'ssi-runtime/includes/product-grid-runtime.php', $form_files, true ) && ! in_array( 'ssi-runtime/includes/internal-link-runtime.php', $form_files, true ), 'form-fixture-ships-only-the-form-runtime' );
// The same form site without the provider's declaration ships no form runtime.
$undeclared_form = Static_Site_Importer_Generated_Runtime_Package::theme( Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $payload, array( $form_resolved ), array() ), 'photo-studio' );
$assert( ! is_wp_error( $undeclared_form ) && ! isset( $undeclared_form['files']['ssi-runtime/includes/provider-form-runtime-v1.php'] ), 'form-runtime-follows-the-declaration-not-the-markup' );

// A provider entity's readable fallback carries generated blocks as parsed
// blocks, not delimiters; its binding renders them into the page after the
// runtime is packaged, so they ship (lipsum.com's generator form keeps its
// table wrappers in a layout-shell).
$entity_lifecycle = array(
	'entities' => array(
		'forms' => array(
			'manifest' => array(
				'forms' => array(
					array(
						'readable_blocks' => array(
							array(
								'blockName'   => 'core/group',
								'attrs'       => array( 'tagName' => 'form' ),
								'innerBlocks' => array(
									array(
										'blockName'   => 'ssi-photo-studio/live-clock',
										'attrs'       => array(),
										'innerBlocks' => array(),
									),
								),
							),
						),
					),
				),
			),
		),
	),
);
$entity_package   = Static_Site_Importer_Generated_Runtime_Package::theme( Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $payload, array( $resolved ), $entity_lifecycle ), 'photo-studio' );
$entity_files     = is_wp_error( $entity_package ) ? array() : Static_Site_Importer_Generated_Runtime_Package::shipped_files( $entity_package );
$assert( in_array( 'ssi-runtime/blocks/live-clock/block.json', $entity_files, true ) && ! in_array( 'ssi-runtime/blocks/motion-sequence/block.json', $entity_files, true ), 'entity-readable-blocks-ship-their-generated-blocks: ' . implode( ', ', $entity_files ) );

// A selected commerce adapter declares commerce the same way.
$shop_lifecycle = array( 'dependencies' => array( 'shop' => array( 'adapter' => Static_Site_Importer_Woo_Product_Seeder::adapter() ) ) );
$shop_package   = Static_Site_Importer_Generated_Runtime_Package::theme( Static_Site_Importer_Generated_Runtime_Package::scoped_payload( $payload, array( $resolved ), $shop_lifecycle ), 'photo-studio' );
$assert( ! is_wp_error( $shop_package ) && isset( $shop_package['files']['ssi-runtime/includes/product-grid-runtime.php'] ) && ! isset( $shop_package['files']['ssi-runtime/includes/provider-form-runtime-v1.php'] ), 'commerce-adapter-declaration-ships-commerce-runtime' );

// Every payload declares its features; plugin-owned companions included.
$undeclared = $payload;
unset( $undeclared['runtime_features'] );
$rejected_theme  = Static_Site_Importer_Generated_Runtime_Package::theme( $undeclared, 'photo-studio' );
$rejected_plugin = Static_Site_Importer_Companion_Plugin::scaffold( $undeclared );
$assert( is_wp_error( $rejected_theme ) && 'static_site_importer_companion_plugin_runtime_features_invalid' === $rejected_theme->get_error_code(), 'undeclared-theme-payload-is-rejected' );
$assert( is_wp_error( $rejected_plugin ) && 'static_site_importer_companion_plugin_runtime_features_invalid' === $rejected_plugin->get_error_code(), 'undeclared-plugin-payload-is-rejected' );
$assert( Static_Site_Importer_Generated_Runtime_Package::with_declared_features( $undeclared, $form_lifecycle ) === $undeclared, 'adapter-declarations-do-not-stand-in-for-a-missing-payload-declaration' );
$plugin_package = Static_Site_Importer_Companion_Plugin::scaffold( Static_Site_Importer_Generated_Runtime_Package::with_declared_features( $payload, $form_lifecycle ) );
$plugin_files   = is_wp_error( $plugin_package ) ? array() : array_keys( $plugin_package['files'] );
$assert( in_array( 'ssi-photo-studio/includes/provider-form-runtime-v1.php', $plugin_files, true ) && ! in_array( 'ssi-photo-studio/includes/product-grid-runtime.php', $plugin_files, true ), 'plugin-owned-companion-follows-the-same-declaration-rule: ' . implode( ', ', $plugin_files ) );
$bad_block                                  = $payload;
$bad_block['blocks'][0]['runtime_features'] = array( 'sliders' );
$assert( is_wp_error( Static_Site_Importer_Companion_Plugin::validate_payload( $bad_block ) ), 'unknown-block-runtime-feature-is-rejected' );

if ( $failures ) {
	throw new RuntimeException( implode( "\n", $failures ) );
}
echo 'PASS: generated theme runtime ships ' . count( $shipped ) . " files for a site that uses no optional runtime\n";
