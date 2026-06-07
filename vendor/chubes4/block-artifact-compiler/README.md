# Block Artifact Compiler

Block Artifact Compiler is the semantic compiler layer between generated website artifacts and WordPress materialization.

It is intentionally separate from Studio Web, Static Site Importer, Block Format Bridge, and HTML to Blocks Converter.

```text
Studio Web
  -> Static Site Importer
      -> Block Artifact Compiler
          -> Block Format Bridge
              -> HTML to Blocks Converter
```

## Responsibility

Input: a user-owned website artifact bundle.

Canonical input schema: `block-artifact-compiler/website-artifact/v1`.

Output: a WordPress-native artifact bundle.

The contract accepts messy AI-generated artifact shapes and normalizes them into a bounded, safe envelope before lower-level conversion runs. It accepts:

- `files`, `artifacts`, or `outputs` arrays
- path-to-content maps
- `content_base64` payloads for binary and encoded text files
- MIME metadata through `mime_type`, `mime`, `media_type`, or MIME-shaped `type`
- file `role`, `intent`, and `entrypoint` metadata
- bundle `entrypoint`, `entry`, `main`, or `entrypoints` metadata
- `html`, `generated_html`, `content`, or `body` strings
- shorthand `css`, `styles`, `js`, `javascript`, or `script` strings

It rejects absolute paths, `..` escapes, empty paths, invalid base64 payloads, oversized files, and over-budget bundles. Rejections are reported as diagnostics so permissive generators can keep producing complete website bundles while BAC normalizes the safe subset for WordPress materializers.

BAC treats Markdown and MDX as source documents, not generic assets:

- `.md` and `.markdown` normalize as `kind: markdown` with `text/markdown`
- `.mdx` normalizes as `kind: mdx` with BAC-local MIME type `text/mdx`
- frontmatter maps to WordPress document metadata such as title, slug, post type, excerpt, date, template, and taxonomy hints
- Markdown bodies are converted through Block Format Bridge when available, with a core/html preservation fallback otherwise
- MDX bodies are reduced to Markdown-compatible text where feasible, while JSX imports/components stay inspectable as candidates and diagnostics

The compiler result returns:

- serialized block markup
- parsed blocks when WordPress parsing is available
- component candidates from explicit `data-component` markers and repeated semantic class tokens
- component candidates from MDX JSX references and generated JSX/TSX component files
- post/page-like `documents` artifacts compiled from HTML, Markdown, and MDX source documents
- generated custom block type artifacts discovered from `block.json` roots
- generated plugin artifacts discovered from WordPress plugin headers
- materializer-facing plugin and custom-block requirements showing which generated artifacts are provided and which custom blocks remain external
- generated file manifest for non-entry artifact files, including MIME type, role, encoding, binary marker, `content_base64` for binary assets, and CSS/JS intent when present or inferred
- generated file manifest provenance, including uncompiled source documents with stable source hashes
- diagnostics
- provenance
- optional BFB conversion report

Block type artifacts are normalized compiler output, not generation prompt constraints. Generation can produce loose files; the compiler identifies block roots, records diagnostics, and exposes a stable contract for downstream review and materialization.

Plugin artifacts follow the same rule. BAC detects WordPress plugin headers, preserves safe header metadata and source file inventories, links generated `block.json` artifacts inside the plugin directory, and reports requirements without installing, activating, or resolving external plugins. Downstream materializers such as Static Site Importer can decide whether a `provided` plugin/custom-block artifact should be promoted or whether an `external` custom-block requirement needs a preinstalled dependency.

## Public API

```php
$result = bac_compile_website_artifact(
	array(
		'schema'         => 'block-artifact-compiler/website-artifact/v1',
		'generated_html' => '<main><h1>Hello</h1></main>',
		'css'            => 'main { max-width: 80rem; }',
		'entrypoints'    => array( 'index.html' ),
		'files' => array(
			array(
				'path'    => 'site.js',
				'content' => 'console.log("preview behavior");',
				'role'    => 'script',
				'intent'  => 'behavior',
			),
			array(
				'path'           => 'assets/logo.png',
				'content_base64' => '...',
				'mime_type'      => 'image/png',
				'role'           => 'brand-asset',
			),
		),
	)
);
```

Result shape:

```php
array(
	'schema'              => 'block-artifact-compiler/result/v1',
	'status'              => 'success',
	'input'               => array(...),
	'wordpress_artifacts' => array(
		'block_markup' => '<!-- wp:paragraph -->...',
		'blocks'       => array(),
		'documents'    => array(
			array(
				'source_path'       => 'website/menu.html',
				'kind'              => 'html',
				'post_type'         => 'page',
				'slug'              => 'menu',
				'title'             => 'Menu',
				'entrypoint'        => false,
				'document_metadata' => array(...),
				'block_markup'      => '<!-- wp:paragraph -->...',
				'provenance'        => array(...),
			),
		),
		'block_types'  => array(
			array(
				'schema'          => 'chubes4/wordpress-block-type-artifact/v1',
				'name'            => 'acme/hero',
				'slug'            => 'hero',
				'directory'       => 'blocks/hero',
				'block_json_path' => 'blocks/hero/block.json',
				'block_json'      => array(...),
				'metadata'        => array(
					'apiVersion' => 3,
					'title'      => 'Hero',
					'category'   => 'design',
					'attributes' => array(...),
					'supports'   => array(...),
				),
				'assets'          => array(
					'render'        => array(),
					'editor_script' => array(),
					'script'        => array(),
					'view_script'   => array(),
					'editor_style'  => array(),
					'style'         => array(),
					'view_style'    => array(),
				),
				'dependencies'    => array(
					'declared'    => array(...),
					'asset_files' => array(),
				),
				'provenance'      => array(
					'source'      => 'files',
					'source_hash' => '...',
					'files'       => array(...),
				),
				'files'           => array(),
			),
		),
		'plugins'      => array(
			array(
				'schema'      => 'chubes4/wordpress-plugin-artifact/v1',
				'slug'        => 'acme-blocks',
				'directory'   => 'plugins/acme-blocks',
				'plugin_file' => 'plugins/acme-blocks/acme-blocks.php',
				'headers'     => array(
					'name'         => 'Acme Blocks',
					'version'      => '0.1.0',
					'requires_php' => '8.1',
				),
				'blocks'      => array(
					array(
						'name'            => 'acme/hero',
						'directory'       => 'plugins/acme-blocks/blocks/hero',
						'block_json_path' => 'plugins/acme-blocks/blocks/hero/block.json',
					),
				),
				'provenance'  => array(...),
				'files'       => array(...),
			),
		),
		'requirements' => array(
			'plugins'       => array(
				array(
					'slug'        => 'acme-blocks',
					'plugin_file' => 'plugins/acme-blocks/acme-blocks.php',
					'source'      => 'plugin_artifact',
					'status'      => 'provided',
				),
			),
			'custom_blocks' => array(
				array(
					'name'      => 'acme/hero',
					'namespace' => 'acme',
					'source'    => 'block_json',
					'status'    => 'provided',
				),
				array(
					'name'      => 'vendor/card',
					'namespace' => 'vendor',
					'source'    => 'block_markup',
					'status'    => 'external',
				),
			),
		),
		'components'   => array(),
		'files'        => array(),
	),
	'provenance'          => array(...),
	'diagnostics'         => array(),
	'bfb_report'          => array(),
)
```

For fragment conversion callers such as Static Site Importer:

```php
$compiled = bac_compile_fragment( $html, 'main:index.html', 'html', $options );
$summary  = bac_summarize_result( $compiled );
$markup   = $compiled['wordpress_artifacts']['block_markup'];
```

## Boundaries

Block Artifact Compiler does not orchestrate agents, import WordPress sites, or deploy outputs.

- Studio Web owns product orchestration, review, preview, and push flows.
- Static Site Importer owns WordPress import and materialization.
- Block Format Bridge owns format-to-block conversion APIs.
- HTML to Blocks Converter owns low-level HTML-to-known-block transforms.

## Smoke Test

```bash
composer test
```

## Homeboy Test

```bash
homeboy test --path /path/to/block-artifact-compiler
```
