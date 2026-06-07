<?php
/**
 * Contract smoke tests.
 *
 * @package BlockArtifactCompiler
 */

require_once dirname( __DIR__ ) . '/library.php';

$assert = static function ( bool $condition, string $message, string $detail = '' ): void {
	if ( $condition ) {
		return;
	}

	fwrite( STDERR, 'FAIL: ' . $message . ( '' !== $detail ? ' - ' . $detail : '' ) . PHP_EOL );
	exit( 1 );
};

$fallback_options = array( 'allow_bfb_unavailable_fallback' => true );

$missing_bfb = bac_compile_fragment( '<main><p>Needs BFB</p></main>', 'production-fragment.html' );
$assert( 'failed' === ( $missing_bfb['status'] ?? '' ), 'missing BFB fails by default for production fragment compilation', (string) ( $missing_bfb['status'] ?? '' ) );
$assert( ! empty( array_filter( $missing_bfb['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'bfb_unavailable' === ( $diagnostic['code'] ?? '' ) && 'error' === ( $diagnostic['severity'] ?? '' ) ) ), 'missing BFB default policy emits an error diagnostic' );

$result = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'index.html',
				'content' => '<main><h1>Hello compiler</h1><p>Initial contract.</p></main>',
			),
		),
	),
	$fallback_options
);

$assert( 'block-artifact-compiler/result/v1' === ( $result['schema'] ?? '' ), 'result exposes schema' );
$assert( 'block-artifact-compiler/website-artifact/v1' === ( $result['input']['schema'] ?? '' ), 'input metadata exposes canonical website artifact schema' );
$assert( 'success_with_warnings' === ( $result['status'] ?? '' ), 'fallback status reflects missing BFB in smoke test', (string) ( $result['status'] ?? '' ) );
$assert( 'index.html' === ( $result['input']['entry_path'] ?? '' ), 'entry path is captured' );
$assert( 3 === ( $result['input']['source_report']['html']['element_count'] ?? null ), 'source HTML element count is reported before conversion' );
$assert( 1 === ( $result['input']['source_report']['html']['landmark_counts']['main'] ?? null ), 'source landmark counts are reported before conversion' );
$assert( str_contains( (string) ( $result['wordpress_artifacts']['block_markup'] ?? '' ), '<!-- wp:html -->' ), 'fallback block markup is produced' );
$assert( isset( $result['wordpress_artifacts']['block_tree'] ) && is_array( $result['wordpress_artifacts']['block_tree'] ), 'generated block tree report is exposed' );
$assert( array() === ( $result['wordpress_artifacts']['block_types'] ?? null ), 'initial contract exposes empty block type list' );
$assert( isset( $result['wordpress_artifacts']['components'] ) && is_array( $result['wordpress_artifacts']['components'] ), 'component candidates are exposed' );

$empty = bac_compile_website_artifact( array( 'files' => array() ) );
$assert( 'failed' === ( $empty['status'] ?? '' ), 'missing HTML fails explicitly' );

$schema_less = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'schema-less.html',
				'content' => '<main><p>No input schema required.</p></main>',
			),
		),
	),
	$fallback_options
);
$assert( 'schema-less.html' === ( $schema_less['input']['entry_path'] ?? '' ), 'bundles without schema still compile' );
$assert( '' === ( $schema_less['input']['original_schema'] ?? null ), 'omitted bundle schema is preserved as empty original schema metadata' );

$warnings = array();
set_error_handler(
	static function ( int $errno, string $errstr ) use ( &$warnings ): bool {
		$warnings[] = $errstr;
		return true;
	}
);
$nested_source = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'nested-source.html',
				'content' => '<main><p>Nested source metadata.</p></main>',
				'source'  => array( 'metadata' => 'object' ),
			),
		),
	),
	$fallback_options
);
restore_error_handler();
$assert( array() === $warnings, 'non-scalar file source metadata does not emit PHP warnings', implode( '; ', $warnings ) );
$assert( 'nested-source.html' === ( $nested_source['input']['entry_path'] ?? '' ), 'non-scalar file source metadata still compiles' );

$messy = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><section class="hero"><h1>Messy</h1></section><article class="card product-card" data-component="Product Card">A</article><article class="card product-card">B</article></main>',
		'css'            => '.card{border:1px solid currentColor}',
		'files'          => array(
			'../secret.txt' => 'nope',
			'app.js'        => 'console.log("preview only");',
			array(
				'path'    => '/absolute.html',
				'content' => 'nope',
			),
		),
	),
	$fallback_options
);
$assert( 'success_with_warnings' === ( $messy['status'] ?? '' ), 'unsafe AI artifact inputs produce warning status', (string) ( $messy['status'] ?? '' ) );
$assert( 2 === ( $messy['input']['rejected_count'] ?? null ), 'unsafe paths are rejected' );
$assert( 'index.html' === ( $messy['input']['entry_path'] ?? '' ), 'generated_html becomes index entry' );
$assert( 1 === ( $messy['input']['files_by_kind']['css'] ?? 0 ), 'css shorthand is normalized' );
$assert( 1 === ( $messy['input']['files_by_kind']['js'] ?? 0 ), 'js file is normalized' );
$assert( ( $messy['input']['source_report']['html']['unique_class_count'] ?? 0 ) >= 3, 'source class inventory is reported' );
$assert( 1 === ( $messy['input']['source_report']['css']['selector_count'] ?? null ), 'source CSS selector inventory is reported' );
$assert( ! empty( $messy['wordpress_artifacts']['components'] ?? array() ), 'component candidates are detected' );

$markdown = bac_compile_website_artifact(
	array(
		'files' => array(
			'content/about.md'       => "---\ntitle: About Us\nslug: about\npost_type: page\ntags: [team, story]\n---\n# About\n\nPlain Markdown content.",
			'content/changelog.markdown' => "---\ntitle: Changelog\npost_type: post\n---\n# Changes",
			'assets/logo.bin'       => 'binary-ish',
		),
	),
	$fallback_options
);
$assert( 'success_with_warnings' === ( $markdown['status'] ?? '' ), 'markdown documents compile with fallback warnings when BFB is unavailable', (string) ( $markdown['status'] ?? '' ) );
$assert( 2 === ( $markdown['input']['files_by_kind']['markdown'] ?? 0 ), 'md and markdown files are classified as markdown' );
$assert( 1 === ( $markdown['input']['files_by_kind']['asset'] ?? 0 ), 'unknown assets remain assets' );
$assert( 2 === count( $markdown['wordpress_artifacts']['documents'] ?? array() ), 'markdown source documents produce WordPress document artifacts' );
$assert( 'about' === ( $markdown['wordpress_artifacts']['documents'][0]['slug'] ?? '' ), 'frontmatter slug is preserved' );
$assert( 'About Us' === ( $markdown['wordpress_artifacts']['documents'][0]['title'] ?? '' ), 'frontmatter title is preserved' );
$assert( str_contains( (string) ( $markdown['wordpress_artifacts']['documents'][0]['block_markup'] ?? '' ), '<!-- wp:html -->' ), 'markdown body is converted or preserved as block markup' );

$mdx = bac_compile_website_artifact(
	array(
		'files' => array(
			'pages/home.mdx'          => "---\ntitle: Home\nslug: home\n---\nimport Hero from '../components/Hero'\nimport { ProductGrid } from '../components/ProductGrid'\n\n# Welcome\n\n<Hero />\n<ProductGrid collection=\"featured\" />\n<MissingWidget />",
			'components/Hero.jsx'     => 'export default function Hero() { return <section />; }',
			'components/ProductGrid.tsx' => 'export function ProductGrid() { return <div />; }',
		),
	),
	$fallback_options
);
$assert( 1 === ( $mdx['input']['files_by_kind']['mdx'] ?? 0 ), 'mdx files are classified as mdx' );
$assert( 1 === ( $mdx['input']['files_by_kind']['jsx'] ?? 0 ), 'jsx files are classified as jsx component sources' );
$assert( 1 === ( $mdx['input']['files_by_kind']['tsx'] ?? 0 ), 'tsx files are classified as tsx component sources' );
$assert( 'text/mdx' === ( $mdx['wordpress_artifacts']['files'][0]['mime_type'] ?? '' ), 'mdx files preserve BAC-local MIME type in file manifest' );
$assert( 1 === count( $mdx['wordpress_artifacts']['documents'] ?? array() ), 'mdx source document produces a document artifact' );
$assert( count( $mdx['wordpress_artifacts']['components'] ?? array() ) >= 3, 'mdx JSX components produce component candidates' );
$assert( ! empty( array_filter( $mdx['wordpress_artifacts']['components'] ?? array(), static fn ( array $component ): bool => 'Hero' === ( $component['name'] ?? '' ) && 'components/Hero.jsx' === ( $component['resolved_path'] ?? '' ) ) ), 'mdx imports resolve to generated source files when present' );
$assert( ! empty( array_filter( $mdx['wordpress_artifacts']['components'] ?? array(), static fn ( array $component ): bool => 'jsx-component-file' === ( $component['signal'] ?? '' ) && 'components/Hero.jsx' === ( $component['source'] ?? '' ) ) ), 'jsx source files produce component candidates' );
$assert( ! empty( array_filter( $mdx['diagnostics'] ?? array(), static fn ( array $diagnostic ): bool => 'mdx_component_unresolved' === ( $diagnostic['code'] ?? '' ) ) ), 'unresolved mdx components emit diagnostics' );

$fragment = bac_compile_fragment( '<div class="feature-card">Feature</div>', 'main:index.html', 'html', $fallback_options );
$assert( 'main-index.html' === ( $fragment['input']['entry_path'] ?? '' ), 'fragment source is normalized to virtual path' );

$markdown_fragment = bac_compile_fragment( '# Feature\n\nMarkdown fragment.', 'content/feature.md', 'markdown', $fallback_options );
$assert( 'content/feature.md' === ( $markdown_fragment['input']['entry_path'] ?? '' ), 'markdown fragment keeps a virtual markdown source path' );
$assert( 'markdown' === ( $markdown_fragment['bfb_report']['source'] ?? '' ), 'markdown fragment routes through BAC conversion envelope' );
$assert( str_contains( (string) ( $markdown_fragment['wordpress_artifacts']['block_markup'] ?? '' ), 'Markdown fragment.' ), 'markdown fragment exposes top-level block markup' );

$blocks_fragment = bac_compile_fragment( '<!-- wp:paragraph --><p>Native blocks</p><!-- /wp:paragraph -->', 'content/native.blocks', 'blocks' );
$assert( 'success' === ( $blocks_fragment['status'] ?? '' ), 'serialized block fragments compile without BFB' );
$assert( str_contains( (string) ( $blocks_fragment['wordpress_artifacts']['block_markup'] ?? '' ), 'Native blocks' ), 'serialized block fragments preserve block markup' );

$full_document = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'index.html',
				'content' => '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Ember & Rye</title><meta name="description" content="Wood-fired bakery"><link rel="stylesheet" href="/assets/site.css"></head><body><header class="site-header"><a href="/">Ember & Rye</a></header><main><section class="hero"><h1>Fire, flour, patience.</h1><p>Small-batch loaves.</p></section></main></body></html>',
			),
		),
	),
	$fallback_options
);
$full_document_markup = (string) ( $full_document['wordpress_artifacts']['block_markup'] ?? '' );
$full_document_metadata = $full_document['wordpress_artifacts']['document_metadata'] ?? array();
$assert( ! str_contains( $full_document_markup, '<meta' ), 'full document meta tags are not emitted as block content', $full_document_markup );
$assert( ! str_contains( $full_document_markup, '<title' ), 'full document title tag is not emitted as block content', $full_document_markup );
$assert( ! str_contains( $full_document_markup, '<link' ), 'full document link tags are not emitted as block content', $full_document_markup );
$assert( str_contains( $full_document_markup, 'Fire, flour, patience.' ), 'full document body content is preserved in block content', $full_document_markup );
$assert( 'block-artifact-compiler/document-metadata/v1' === ( $full_document_metadata['schema'] ?? '' ), 'full document exposes metadata contract' );
$assert( 'Ember & Rye' === ( $full_document_metadata['title'] ?? '' ), 'full document title is routed to metadata contract' );
$assert( 'utf-8' === ( $full_document_metadata['meta'][0]['charset'] ?? '' ), 'charset meta is routed to metadata contract' );
$assert( 'viewport' === ( $full_document_metadata['meta'][1]['name'] ?? '' ), 'viewport meta is routed to metadata contract' );
$assert( '/assets/site.css' === ( $full_document_metadata['links'][0]['href'] ?? '' ), 'stylesheet link is routed to metadata contract' );

$multi_page = bac_compile_website_artifact(
	array(
		'schema'     => 'block-artifact-compiler/website-artifact/v1',
		'entrypoint' => 'website/index.html',
		'files'      => array(
			array(
				'path'    => 'website/index.html',
				'content' => '<!doctype html><html><head><title>Home Page</title></head><body><main><h1>Home</h1><p>Welcome.</p></main></body></html>',
			),
			array(
				'path'    => 'website/menu.html',
				'content' => '<!doctype html><html><head><title>Menu Page</title><meta name="description" content="Seasonal menu"></head><body><main><h1>Menu</h1><p>Pizza and small plates.</p></main></body></html>',
			),
			array(
				'path'    => 'website/contact.html',
				'content' => '<main><h1>Contact</h1><p>Email us.</p></main>',
			),
		)
	),
	$fallback_options
);
$multi_documents = $multi_page['wordpress_artifacts']['documents'] ?? array();
$assert( 3 === count( $multi_documents ), 'multi-page HTML artifacts expose one document per HTML file' );
$assert( 'index' === ( $multi_documents[0]['slug'] ?? '' ), 'entry index slug comes from filename' );
$assert( true === ( $multi_documents[0]['entrypoint'] ?? null ), 'entry HTML document preserves entrypoint identity' );
$assert( 'Home Page' === ( $multi_documents[0]['title'] ?? '' ), 'entry HTML document title comes from metadata' );
$assert( 'menu' === ( $multi_documents[1]['slug'] ?? '' ), 'nested HTML page slug comes from filename' );
$assert( 'Menu Page' === ( $multi_documents[1]['title'] ?? '' ), 'nested HTML document title comes from metadata' );
$assert( 'Seasonal menu' === ( $multi_documents[1]['document_metadata']['meta'][0]['content'] ?? '' ), 'nested HTML document metadata is preserved' );
$assert( str_contains( (string) ( $multi_documents[2]['block_markup'] ?? '' ), 'Contact' ), 'HTML document block markup preserves body content' );

$compiled_site = $multi_page['wordpress_artifacts']['site'] ?? array();
$assert( 'block-artifact-compiler/compiled-site/v1' === ( $compiled_site['schema'] ?? '' ), 'compiled site artifact exposes schema' );
$assert( 3 === count( $compiled_site['pages'] ?? array() ), 'compiled site artifact exposes page routes' );
$assert( 'menu' === ( $compiled_site['pages'][1]['route_key'] ?? '' ), 'compiled site page route keys come from document slugs' );

$shared_chrome = bac_compile_website_artifact(
	array(
		'files' => array(
			'home.html'  => '<!doctype html><html><body><header class="site-header">Shared nav</header><main><h1>Home</h1></main><footer>Shared footer</footer></body></html>',
			'about.html' => '<!doctype html><html><body><header class="site-header">Shared nav</header><main><h1>About</h1></main><footer>Shared footer</footer></body></html>',
			'site.css'   => 'body{font-family:sans-serif}',
			'site.js'    => 'console.log("site")',
		),
	),
	$fallback_options
);
$shared_regions = $shared_chrome['wordpress_artifacts']['site']['shared_regions'] ?? array();
$assert( ! empty( array_filter( $shared_regions, static fn ( array $region ): bool => 'header' === ( $region['role'] ?? '' ) && 2 === count( $region['source_paths'] ?? array() ) ) ), 'compiled site artifact exposes shared header chrome candidates' );
$assert( ! empty( array_filter( $shared_regions, static fn ( array $region ): bool => 'footer' === ( $region['role'] ?? '' ) && 2 === count( $region['source_paths'] ?? array() ) ) ), 'compiled site artifact exposes shared footer chrome candidates' );
$assert( 1 === count( $shared_chrome['wordpress_artifacts']['site']['theme_assets']['styles'] ?? array() ), 'compiled site artifact exposes theme style assets' );
$assert( 1 === count( $shared_chrome['wordpress_artifacts']['site']['theme_assets']['scripts'] ?? array() ), 'compiled site artifact exposes theme script assets' );

$summary = bac_summarize_result( $messy );
$assert( ( $summary['component_count'] ?? 0 ) > 0, 'summary exposes component count' );
$assert( ( $summary['source_element_count'] ?? 0 ) > 0, 'summary exposes source element count' );
$assert( ( $summary['source_css_selector_count'] ?? 0 ) > 0, 'summary exposes source CSS selector count' );

$rich = bac_compile_website_artifact(
	array(
		'schema'      => 'example/rich-website-bundle/v1',
		'entrypoints' => array( 'pages/home.html', '../unsafe.html' ),
		'files'       => array(
			array(
				'path'           => 'pages/home.html',
				'content_base64' => base64_encode( '<main><h1>Rich bundle</h1></main>' ),
				'mime_type'      => 'text/html',
				'role'           => 'entry',
			),
			array(
				'path'    => 'assets/app.css',
				'content' => 'body{color:rebeccapurple}',
				'type'    => 'text/css',
				'intent'  => 'theme-style',
			),
			array(
				'path'           => 'assets/logo.png',
				'content_base64' => base64_encode( "\x89PNG\r\n\x1a\n" ),
				'mime_type'      => 'image/png',
				'role'           => 'brand-asset',
			),
			array(
				'path'           => 'assets/bad.bin',
				'content_base64' => 'not-valid-base64',
			),
		),
	),
	$fallback_options
);
$assert( 'pages/home.html' === ( $rich['input']['entry_path'] ?? '' ), 'explicit entrypoint selects nested HTML entry' );
$assert( in_array( 'pages/home.html', $rich['input']['entrypoints'] ?? array(), true ), 'entrypoints are normalized into input metadata' );
$assert( 1 === ( $rich['input']['files_by_role']['brand-asset'] ?? 0 ), 'explicit asset role is preserved' );
$assert( 1 === ( $rich['input']['files_by_mime']['image/png'] ?? 0 ), 'MIME counts are exposed' );
$assert( 1 === ( $rich['input']['rejected_count'] ?? null ), 'invalid base64 file is rejected without blocking the bundle' );
$has_unsafe_entrypoint = false;
foreach ( $rich['diagnostics'] ?? array() as $diagnostic ) {
	if ( 'unsafe_entrypoint_path' === ( $diagnostic['code'] ?? '' ) ) {
		$has_unsafe_entrypoint = true;
	}
}
$assert( $has_unsafe_entrypoint, 'unsafe entrypoint is diagnosed' );
$asset_files = $rich['wordpress_artifacts']['files'] ?? array();
$png_file    = null;
foreach ( $asset_files as $asset_file ) {
	if ( 'assets/logo.png' === ( $asset_file['path'] ?? '' ) ) {
		$png_file = $asset_file;
	}
}
$assert( is_array( $png_file ), 'binary asset appears in file manifest' );
$assert( ! empty( $png_file['content_base64'] ?? '' ), 'binary asset keeps base64 payload' );
$assert( true === ( $png_file['binary'] ?? null ), 'binary asset is marked binary' );

$blocks = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><h1>Block artifact page</h1></main>',
		'files'          => array(
			'blocks/hero/block.json'       => bac_json_encode(
				array(
					'apiVersion'   => 3,
					'name'         => 'acme/hero',
					'title'        => 'Hero',
					'category'     => 'design',
					'editorScript' => 'file:./index.js',
					'viewScript'   => array( 'file:./view.js', 'wp-interactivity' ),
					'style'        => 'file:./style.css',
					'editorStyle'  => 'file:./editor.css',
					'render'       => 'file:./render.php',
					'attributes'   => array(
						'headline' => array( 'type' => 'string' ),
					),
					'supports'     => array( 'align' => true ),
				),
				JSON_UNESCAPED_SLASHES
			),
			'blocks/hero/index.js'         => 'import metadata from "./block.json";',
			'blocks/hero/index.asset.php'  => '<?php return array("dependencies" => array("wp-blocks"), "version" => "1");',
			'blocks/hero/view.js'          => 'console.log("front");',
			'blocks/hero/style.css'        => '.wp-block-acme-hero{padding:2rem}',
			'blocks/hero/editor.css'       => '.wp-block-acme-hero{outline:1px solid}',
			'blocks/hero/render.php'       => '<?php echo $content;',
		),
	),
	$fallback_options
);
$block_types = $blocks['wordpress_artifacts']['block_types'] ?? array();
$assert( 1 === count( $block_types ), 'block.json roots are promoted into block type artifacts' );
$hero = $block_types[0] ?? array();
$assert( 'chubes4/wordpress-block-type-artifact/v1' === ( $hero['schema'] ?? '' ), 'block type exposes contract schema' );
$assert( 'acme/hero' === ( $hero['name'] ?? '' ), 'block type preserves block.json name' );
$assert( 'blocks/hero' === ( $hero['directory'] ?? '' ), 'block type exposes source directory' );
$assert( 'blocks/hero/block.json' === ( $hero['block_json_path'] ?? '' ), 'block type exposes block.json path' );
$assert( 3 === ( $hero['metadata']['apiVersion'] ?? null ), 'block metadata preserves apiVersion' );
$assert( array( 'align' => true ) === ( $hero['metadata']['supports'] ?? null ), 'block metadata preserves supports' );
$assert( 'blocks/hero/index.js' === ( $hero['assets']['editor_script'][0]['path'] ?? '' ), 'editor script file reference resolves to generated file' );
$assert( 'wp-interactivity' === ( $hero['assets']['view_script'][1]['reference'] ?? '' ), 'script handles are preserved as dependencies/references' );
$assert( 'blocks/hero/render.php' === ( $hero['assets']['render'][0]['path'] ?? '' ), 'render file reference resolves to generated file' );
$assert( 'blocks/hero/index.asset.php' === ( $hero['dependencies']['asset_files'][0]['path'] ?? '' ), 'asset php dependency manifests are recorded' );
$assert( ! empty( $hero['provenance']['source_hash'] ?? '' ), 'block type exposes provenance hash' );
$assert( in_array( 'blocks/hero/style.css', $hero['provenance']['files'] ?? array(), true ), 'block provenance lists source files' );

$summary = bac_summarize_result( $blocks );
$assert( 1 === ( $summary['block_type_count'] ?? 0 ), 'summary exposes block type count' );

$plugin_bundle = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><!-- wp:acme/hero {"headline":"Plugin block"} /--><!-- wp:vendor/card /--></main>',
		'files'          => array(
			'plugins/acme-blocks/acme-blocks.php'        => "<?php\n/**\n * Plugin Name: Acme Blocks\n * Description: Generated custom blocks.\n * Version: 0.1.0\n * Requires PHP: 8.1\n * Text Domain: acme-blocks\n */",
			'plugins/acme-blocks/blocks/hero/block.json' => bac_json_encode(
				array(
					'apiVersion' => 3,
					'name'       => 'acme/hero',
					'title'      => 'Plugin Hero',
					'category'   => 'design',
				),
				JSON_UNESCAPED_SLASHES
			),
			'plugins/acme-blocks/blocks/hero/index.js'   => 'wp.blocks.registerBlockType("acme/hero", {});',
		),
	),
	$fallback_options
);
$plugins = $plugin_bundle['wordpress_artifacts']['plugins'] ?? array();
$assert( 1 === count( $plugins ), 'plugin header files are promoted into plugin artifacts' );
$plugin = $plugins[0] ?? array();
$assert( 'chubes4/wordpress-plugin-artifact/v1' === ( $plugin['schema'] ?? '' ), 'plugin artifact exposes contract schema' );
$assert( 'acme-blocks' === ( $plugin['slug'] ?? '' ), 'plugin artifact exposes inferred slug' );
$assert( 'Acme Blocks' === ( $plugin['headers']['name'] ?? '' ), 'plugin artifact preserves Plugin Name header' );
$assert( '8.1' === ( $plugin['headers']['requires_php'] ?? '' ), 'plugin artifact preserves Requires PHP header' );
$assert( 'plugins/acme-blocks/acme-blocks.php' === ( $plugin['plugin_file'] ?? '' ), 'plugin artifact exposes primary plugin file' );
$assert( 'acme/hero' === ( $plugin['blocks'][0]['name'] ?? '' ), 'plugin artifact links generated block types in the plugin directory' );

$requirements = $plugin_bundle['wordpress_artifacts']['requirements'] ?? array();
$assert( 1 === count( $requirements['plugins'] ?? array() ), 'requirements expose provided plugin artifacts' );
$assert( 'provided' === ( $requirements['plugins'][0]['status'] ?? '' ), 'plugin requirements mark generated plugin artifacts as provided' );
$provided_block = null;
$external_block = null;
foreach ( $requirements['custom_blocks'] ?? array() as $requirement ) {
	if ( 'acme/hero' === ( $requirement['name'] ?? '' ) ) {
		$provided_block = $requirement;
	}
	if ( 'vendor/card' === ( $requirement['name'] ?? '' ) ) {
		$external_block = $requirement;
	}
}
$assert( is_array( $provided_block ), 'requirements include custom block usage satisfied by generated block.json' );
$assert( 'provided' === ( $provided_block['status'] ?? '' ), 'provided custom block requirement is marked provided' );
$assert( is_array( $external_block ), 'requirements include external custom block usage' );
$assert( 'external' === ( $external_block['status'] ?? '' ), 'external custom block requirement stays external for downstream resolution' );

$summary = bac_summarize_result( $plugin_bundle );
$assert( 1 === ( $summary['plugin_artifact_count'] ?? 0 ), 'summary exposes plugin artifact count' );
$assert( 2 === ( $summary['custom_block_requirement_count'] ?? 0 ), 'summary exposes custom block requirement count' );

fwrite( STDOUT, "contract smoke passed\n" );
