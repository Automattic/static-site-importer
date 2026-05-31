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

Output: a WordPress-native artifact bundle.

The contract accepts messy AI-generated artifact shapes and normalizes them into a bounded, safe envelope before lower-level conversion runs. It accepts:

- `files`, `artifacts`, or `outputs` arrays
- path-to-content maps
- `html`, `generated_html`, `content`, or `body` strings
- shorthand `css`, `styles`, `js`, `javascript`, or `script` strings

It rejects absolute paths, `..` escapes, empty paths, oversized files, and over-budget bundles.

The compiler result returns:

- serialized block markup
- parsed blocks when WordPress parsing is available
- component candidates from explicit `data-component` markers and repeated semantic class tokens
- generated block type manifest placeholder
- generated file manifest for non-entry artifact files
- diagnostics
- provenance
- optional BFB conversion report

Future compiler passes can promote component candidates into generated custom block artifacts without changing the caller boundary.

## Public API

```php
$result = bac_compile_website_artifact(
	array(
		'generated_html' => '<main><h1>Hello</h1></main>',
		'css'            => 'main { max-width: 80rem; }',
		'files' => array(
			array(
				'path'    => 'site.js',
				'content' => 'console.log("preview behavior");',
			),
		),
	)
);
```

Result shape:

```php
array(
	'schema'              => 'chubes4/block-artifact-compiler-result/v1',
	'status'              => 'success',
	'input'               => array(...),
	'wordpress_artifacts' => array(
		'block_markup' => '<!-- wp:paragraph -->...',
		'blocks'       => array(),
		'block_types'  => array(),
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
