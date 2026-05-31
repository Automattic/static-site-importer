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

$result = bac_compile_website_artifact(
	array(
		'files' => array(
			array(
				'path'    => 'index.html',
				'content' => '<main><h1>Hello compiler</h1><p>Initial contract.</p></main>',
			),
		),
	)
);

$assert( 'block-artifact-compiler/result/v1' === ( $result['schema'] ?? '' ), 'result exposes schema' );
$assert( 'block-artifact-compiler/website-artifact/v1' === ( $result['input']['schema'] ?? '' ), 'input metadata exposes canonical website artifact schema' );
$assert( 'success_with_warnings' === ( $result['status'] ?? '' ), 'fallback status reflects missing BFB in smoke test', (string) ( $result['status'] ?? '' ) );
$assert( 'index.html' === ( $result['input']['entry_path'] ?? '' ), 'entry path is captured' );
$assert( str_contains( (string) ( $result['wordpress_artifacts']['block_markup'] ?? '' ), '<!-- wp:html -->' ), 'fallback block markup is produced' );
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
	)
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
	)
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
	)
);
$assert( 'success_with_warnings' === ( $messy['status'] ?? '' ), 'unsafe AI artifact inputs produce warning status', (string) ( $messy['status'] ?? '' ) );
$assert( 2 === ( $messy['input']['rejected_count'] ?? null ), 'unsafe paths are rejected' );
$assert( 'index.html' === ( $messy['input']['entry_path'] ?? '' ), 'generated_html becomes index entry' );
$assert( 1 === ( $messy['input']['files_by_kind']['css'] ?? 0 ), 'css shorthand is normalized' );
$assert( 1 === ( $messy['input']['files_by_kind']['js'] ?? 0 ), 'js file is normalized' );
$assert( ! empty( $messy['wordpress_artifacts']['components'] ?? array() ), 'component candidates are detected' );

$markdown = bac_compile_website_artifact(
	array(
		'files' => array(
			'content/about.md'       => "---\ntitle: About Us\nslug: about\npost_type: page\ntags: [team, story]\n---\n# About\n\nPlain Markdown content.",
			'content/changelog.markdown' => "---\ntitle: Changelog\npost_type: post\n---\n# Changes",
			'assets/logo.bin'       => 'binary-ish',
		),
	)
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
	)
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

$fragment = bac_compile_fragment( '<div class="feature-card">Feature</div>', 'main:index.html' );
$assert( 'main-index.html' === ( $fragment['input']['entry_path'] ?? '' ), 'fragment source is normalized to virtual path' );

$summary = bac_summarize_result( $messy );
$assert( ( $summary['component_count'] ?? 0 ) > 0, 'summary exposes component count' );

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
	)
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
			'blocks/hero/block.json'       => wp_json_encode(
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
	)
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

fwrite( STDOUT, "contract smoke passed\n" );
