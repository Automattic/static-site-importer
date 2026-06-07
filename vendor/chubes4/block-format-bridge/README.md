# Block Format Bridge

A WordPress plugin **and Composer package** for content format conversion and declared-format normalization across HTML,
Blocks, and Markdown.

The bridge owns no parsing logic of its own. It composes existing libraries — [`chubes4/html-to-blocks-converter`](https://github.com/chubes4/html-to-blocks-converter),
WordPress core's `serialize_blocks()` / `parse_blocks()` / `render_block()`, [`league/commonmark`](https://github.com/thephpleague/commonmark),
and [`league/html-to-markdown`](https://github.com/thephpleague/html-to-markdown) — behind one contract. New formats
become available by registering a new adapter; the bridge core never grows.

> **Status:** `bfb_convert()`, `bfb_normalize()`, insert-time conversion, and REST `?content_format=` are covered by the
> smoke/Playground test suite.

## What it does

BFB exposes two related but separate public surfaces:

- **`bfb_convert()`** converts content between two different formats. It uses WordPress block arrays as the pivot.
- **`bfb_to_blocks()`** converts a supported source format directly into parsed WordPress block arrays for compilers.
- **`bfb_normalize()`** validates and normalizes content that already claims to be in one format.

| Conversion direction | Underlying tool                        |
|----------------------|----------------------------------------|
| HTML → Blocks        | `chubes4/html-to-blocks-converter`     |
| Blocks → HTML        | `parse_blocks()` + `render_block()` (WordPress core) |
| Markdown → HTML      | `league/commonmark` (vendor-prefixed)  |
| Markdown → Blocks    | composition: Markdown → HTML → Blocks  |
| Blocks → Markdown    | `parse_blocks()` + `render_block()` + `league/html-to-markdown` (vendor-prefixed) |
| HTML → Markdown      | composition: HTML → Blocks → Markdown  |

## Architecture

Every adapter implements the `BFB_Format_Adapter` contract:

```php
interface BFB_Format_Adapter {
    public function slug(): string;
    public function to_blocks( string $content, array $options = array() ): array;
    public function from_blocks( array $blocks, array $options = array() ): string;
}
```

BFB is a declared-format conversion API. Callers pass the source format explicitly to `bfb_convert()` or
`bfb_to_blocks()`; BFB does not silently guess between HTML, Blocks, Markdown, or custom adapters.

BFB includes two adapters:

- **`BFB_HTML_Adapter`** — `to_blocks()` delegates to `html_to_blocks_raw_handler()` from `html-to-blocks-converter`;
  `from_blocks()` returns rendered HTML via `render_block()` (so dynamic blocks resolve to their server-side output).
- **`BFB_Markdown_Adapter`** — `to_blocks()` runs CommonMark + GFM and routes the resulting HTML through the HTML
  adapter. `from_blocks()` renders blocks via `render_block()` and pipes the HTML through league/html-to-markdown.

Markdown input is treated as a content body only. BFB does not parse YAML frontmatter, TOML frontmatter, or any other
document metadata envelope; callers that import files are responsible for stripping and interpreting frontmatter before
passing the body to `bfb_convert( $markdown, 'markdown', 'blocks' )`. BFB also does not support MDX component syntax unless
a future dedicated adapter is registered for it.

Every cross-format conversion routes through the block-array pivot:

```
$blocks = bfb_to_blocks( $content, $from );
return    $to_adapter->from_blocks( $blocks );
```

Declared-format normalization validates the declared format directly:

- `blocks` requires coherent serialized block comments and rejects raw HTML/Markdown between top-level blocks.
- `markdown` normalizes line endings and rejects serialized block comments mixed into Markdown.
- `html` rejects serialized block comments and Markdown markers that indicate mixed input.
- Unsupported formats return `WP_Error`; registered custom formats currently pass through unchanged.

### BFB and h2bc responsibility split

BFB owns format routing and orchestration. It decides which adapter handles a source format, normalises non-block
formats through the block-array pivot, and exposes one public API for callers that do not want to know which lower-level
library performs a specific conversion. It does **not** own per-block raw transforms.

HTML → core block transforms belong to [`chubes4/html-to-blocks-converter`](https://github.com/chubes4/html-to-blocks-converter)
(h2bc). BFB inherits h2bc support through `BFB_HTML_Adapter::to_blocks()`, so new h2bc transforms become available to
BFB after the bundled dependency is updated and rebuilt.

The explicit API path is:

```php
bfb_convert( $html, 'html', 'blocks' )
    -> BFB_HTML_Adapter::to_blocks()
    -> html_to_blocks_raw_handler();
```

The insert/update hook path is split by source format:

- **BFB priority 5:** `wp_insert_post_data` handles non-HTML source formats, such as Markdown, before WordPress stores
  the post. The adapter path normalises those formats to block markup.
- **h2bc priority 10:** `wp_insert_post_data` handles HTML source content and converts it to core block markup.

Both paths are server-side and deterministic. There is no AI conversion pass in BFB or h2bc.

Block-theme structure and Site Editor behavior are higher-level concerns. Raw HTML can describe markup, but it often
cannot encode intent such as template areas, patterns, block locking, global style relationships, or theme-specific
structure. When that intent is required, use a compiler or generation layer above BFB/h2bc, then pass the resulting block
markup through the normal storage/rendering path.

### Explicit Site Editor primitive markers

BFB defines the public marker vocabulary for Site Editor primitives that cannot be inferred safely from arbitrary HTML.
Only BFB-owned attributes are part of this contract:

| Primitive | Marker | Deterministic block target |
|-----------|--------|----------------------------|
| Pattern reference | `data-bfb-pattern="namespace/slug"` | `core/pattern` with `slug: "namespace/slug"` |
| Template part reference | `data-bfb-template-part="area-or-slug"` | `core/template-part` with `slug` and, when applicable, `area` |

Rules:

- BFB and h2bc must never infer patterns or template parts from layout, tag names, classes, or visual similarity.
- `data-wp-*` aliases are intentionally not accepted. WordPress does not currently define those source-HTML markers, and
  BFB should not mint WordPress-looking attributes for its own API.
- Pattern markers require a fully-qualified `namespace/slug` value.
- Template-part markers accept the standard Site Editor areas (`header`, `footer`, `sidebar`) as shorthand values. Other
  values are treated as explicit template-part slugs.
- Missing or malformed marker values should fall back to the normal HTML conversion path rather than guessing.

The marker contract belongs in BFB because BFB is the public conversion substrate. The runtime HTML-element transforms
are currently supplied by h2bc through BFB's bundled dependency, and BFB verifies those markers through the public
`bfb_convert( $html, 'html', 'blocks' )` path. h2bc should treat these attributes as an explicit shared extension
contract with BFB, not as a license to infer Site Editor primitives from unmarked HTML.

## Install

Install it as a standalone plugin, or bundle it as a Composer package.

Data Machine v0.88.0+ bundles BFB as its content-format substrate. Data Machine-powered sites do not need the standalone
BFB plugin unless they also want to manage BFB independently.

### Composer via GitHub VCS

BFB has tagged GitHub releases, but it is not currently published on Packagist, WordPress.org, or wp-packages.org. Until
one of those mirrors exists, Composer consumers should install it from the GitHub VCS repository:

```bash
composer config repositories.bfb vcs https://github.com/chubes4/block-format-bridge
composer require chubes4/block-format-bridge:^0.5
```

Use `dev-main` only when intentionally tracking unreleased development commits.

Composer autoloads `library.php`, which registers the full bridge service: adapters, `bfb_convert()`,
`bfb_normalize()`, `bfb_render_post()`, insert-time conversion, and REST `?content_format=`.

HTML → Blocks support is bundled via [`chubes4/html-to-blocks-converter`](https://github.com/chubes4/html-to-blocks-converter)
as a Composer package. You do **not** need the standalone html-to-blocks-converter plugin active for BFB to convert
HTML/Markdown into block markup.

### Publishing status

- **GitHub releases:** available at https://github.com/chubes4/block-format-bridge/releases.
- **Packagist:** not published yet; publishing there would keep the Composer package name
  `chubes4/block-format-bridge`.
- **WordPress.org:** not published yet; `readme.txt` is present to prepare for plugin-directory review, but no submission
  has been made from this repository.
- **wp-packages.org:** not published yet. wp-packages.org mirrors WordPress.org plugins as `wp-plugin/<slug>`, so BFB
  will only appear there after a WordPress.org plugin-directory listing exists.

If BFB is approved on WordPress.org under the `block-format-bridge` slug, the wp-packages.org install path will be:

```bash
composer config repositories.wp-packages composer https://repo.wp-packages.org
composer require wp-plugin/block-format-bridge
```

### Build from source

```bash
git clone https://github.com/chubes4/block-format-bridge.git
cd block-format-bridge
composer install
composer build  # runs php-scoper to vendor-prefix h2bc + markdown dependencies
```

## Usage

### `bfb_convert( $content, $from, $to ): string`

Universal conversion. Routes through the block-array pivot via the adapter registry.

When `$from === $to`, `bfb_convert()` returns the content unchanged. Use `bfb_normalize()` for same-format validation.

```php
// Markdown → blocks (serialised block markup)
$blocks = bfb_convert( "# Hello\n\nWorld", 'markdown', 'blocks' );

// HTML → blocks
$blocks = bfb_convert( '<h1>Hello</h1><p>World</p>', 'html', 'blocks' );

// Blocks → HTML (rendered through render_block())
$html = bfb_convert( $serialised_blocks, 'blocks', 'html' );

// Blocks → markdown
$md = bfb_convert( $serialised_blocks, 'blocks', 'markdown' );

// HTML → markdown (composes via blocks)
$md = bfb_convert( '<h1>X</h1>', 'html', 'markdown' );

// Markdown → HTML (composes via blocks)
$html = bfb_convert( '# X', 'markdown', 'html' );

// HTML → blocks with importer-neutral per-call context forwarded to h2bc args.
$blocks = bfb_convert(
    '<h1>Hello</h1><p>World</p>',
    'html',
    'blocks',
    array(
        'context' => array(
            'source' => 'static-site-importer',
            'mode'   => 'import',
        ),
    )
);
```

The optional fourth argument is a generic per-call options array. For HTML → Blocks, BFB forwards those options alongside
the reserved `HTML` argument passed to `html_to_blocks_raw_handler()`, so downstream tools can pass structured `context`
without BFB gaining importer-specific API.

### `bfb_to_blocks( $content, $from ): array`

Compiler-facing conversion helper. Use this when you need parsed block arrays for inspection, splitting, policy checks,
or template/pattern assembly instead of serialized block markup.

```php
$blocks = bfb_to_blocks( '<h1>Hello</h1><p>World</p>', 'html' );

foreach ( $blocks as $block ) {
    // parse_blocks()-compatible arrays.
}
```

Contract:

- `from === 'blocks'` parses serialized block markup with `parse_blocks()`.
- Other formats resolve through `bfb_get_adapter( $from )` and call the adapter's `to_blocks()` method.
- Unsupported source formats return an empty array and log the same style of error as `bfb_convert()`.

### WP-CLI: `wp bfb convert`

Non-PHP callers can route conversion through the same public BFB APIs:

```bash
wp bfb convert --from=html --to=blocks < input.html
wp bfb convert --from=blocks --to=markdown --input=post.html --output=post.md
wp bfb convert --from=html --to=blocks --as=json < input.html
```

The command reads STDIN when `--input` is omitted and writes STDOUT when `--output` is omitted. Default output is serialized
content; use `--as=json` with `--to=blocks` when a block-array JSON document is needed.

### `bfb_normalize( $content, $format, $options = array() ): string|WP_Error`

Validate and normalize content already declared as one format. Use this for imported or generated content before storage.

```php
$normalized = bfb_normalize( $maybe_blocks, 'blocks' );

if ( is_wp_error( $normalized ) ) {
    return $normalized;
}

wp_insert_post( array(
    'post_type'    => 'post',
    'post_content' => $normalized,
) );
```

Contract:

- Valid serialized block markup, HTML, and Markdown normalize idempotently.
- Block markup with unclosed, malformed, or mismatched block comments returns `WP_Error`.
- Declared block content with raw HTML or Markdown outside top-level block comments returns `WP_Error`.
- Declared Markdown containing serialized block comments returns `WP_Error`.
- Declared HTML containing serialized block comments or obvious Markdown markers returns `WP_Error`.
- Markdown line endings are normalized to `\n`.
- Unsupported formats return `WP_Error`; registered custom formats currently pass through unchanged.

Detectable malformed or mixed input returns `WP_Error` instead of silently passing through.

### `bfb_render_post( $post, $format ): string`

Read a post's `post_content` in the requested format. Routes through `bfb_convert()` with `'blocks'` as the source.

```php
$html = bfb_render_post( $post_id, 'html' );      // rendered block HTML
$md   = bfb_render_post( $post_id, 'markdown' );  // GFM
```

### REST: `?content_format=<slug>`

Every REST-enabled post type accepts a `content_format` query parameter. When present, the response gains a sibling
`content.formatted` field rendered via `bfb_render_post()`. The existing `content.raw` and `content.rendered` fields
are left untouched.

```bash
curl 'https://example.com/wp-json/wp/v2/posts/123?content_format=markdown'
```

```json
{
  "content": {
    "raw": "<!-- wp:heading ...",
    "rendered": "<h1 class=\"wp-block-heading\">...</h1>",
    "format": "markdown",
    "formatted": "# Hello\n\nBody."
  }
}
```

Full HTTP content negotiation (`Accept: text/markdown`, `.md` URL suffix, q-values, 406 Not Acceptable) is intentionally
out of scope here — that's the job of [`roots/post-content-to-markdown`](https://github.com/roots/post-content-to-markdown)
when active. The bridge surface is the simpler, programmatic query-param form.

### `bfb_get_adapter( $slug ): ?BFB_Format_Adapter`

Resolve a registered adapter directly. Prefer `bfb_to_blocks()` when callers need block arrays instead of adapter internals.

### Block Theme Compiler Consumers

Static HTML/CSS to block-theme compilers should treat BFB as the format-conversion substrate, not the layer that infers
block-theme or Site Editor intent. The compiler-facing helper and CLI shape are documented in
[`docs/block-theme-compiler-surface.md`](docs/block-theme-compiler-surface.md). The stack workflow across h2bc, BFB, and
compiler consumers is documented in [`docs/block-theme-conversion-workflow.md`](docs/block-theme-conversion-workflow.md).
The public mechanical conversion scope matrix is documented in
[`docs/mechanical-block-theme-conversion.md`](docs/mechanical-block-theme-conversion.md).

### Custom block Markdown rendering contract

BFB's Blocks → Markdown path is render-output based:

```text
parse_blocks() -> render_block() -> league/html-to-markdown
```

That means BFB converts the front-end HTML a block renders. It does not infer Markdown semantics from block comments,
attributes, editor-only scaffolding, JSON blobs, placeholders, or empty render output. Custom blocks that want useful
Markdown output should treat their front-end render contract as the source of truth.

Custom block expectations:

- Render semantic front-end HTML for the content you want represented in Markdown, such as headings, paragraphs, lists,
  tables, links, images, blockquotes, and code blocks.
- Keep editor-only scaffolding, inspector state, placeholders, and machine JSON out of saved or rendered output unless
  that material should appear in the Markdown.
- If semantic HTML is not enough for the block's content model, register a block-specific converter through
  `bfb_html_to_markdown_converter` and let league/html-to-markdown handle that rendered HTML explicitly.

For example, a dynamic block that renders `<h2>Release notes</h2><ul><li>Item</li></ul>` can produce meaningful
Markdown. A block that only renders `<div data-state="{...}"></div>` or an empty placeholder cannot; BFB has no safe
way to reconstruct the missing author-facing Markdown from the block comment alone.

### Filters

- **`bfb_default_format( $format, $post_type, $content ): string`** — declares which format a CPT writes in by default.
  Hooks into `wp_insert_post_data` so any code path that calls `wp_insert_post()` (REST, WP-CLI, abilities, plugin
  internals) gets the same conversion behaviour.

  ```php
  add_filter( 'bfb_default_format', function ( $format, $post_type ) {
      return $post_type === 'wiki' ? 'markdown' : $format;
  }, 10, 2 );
  ```

- **`bfb_skip_insert_conversion( $skip, $data, $postarr, $format ): bool`** — lets storage layers veto BFB's
  insert-time format → blocks normalisation after the source format is resolved. Use this when another plugin owns the
  canonical `post_content` shape, such as a markdown-on-disk store that needs raw markdown to remain raw markdown.
- **`bfb_markdown_input( $markdown ): string`** — pre-processes Markdown before CommonMark runs.
- **`bfb_register_format_adapter( $adapter, $slug ): ?BFB_Format_Adapter`** — lazy adapter registration.
- **`bfb_rest_supported_post_types( $post_types ): array`** — restricts which CPTs honour `?content_format=`.
- **`bfb_html_to_markdown_options( $options, $html ): array`** — option array passed to league/html-to-markdown
  (mirrors `roots/post-content-to-markdown`'s `converter_options`).
- **`bfb_html_to_markdown_converter( $converter ): void`** — action fired after the html-to-markdown converter is built
  and before it runs, so consumers can register additional league/html-to-markdown converters.
- **`bfb_markdown_output( $markdown, $html, $blocks ): string`** — final filter on the markdown produced by
  `from_blocks()`.
- **`bfb_loaded( $version ): void`** — action fired after the winning BFB package/plugin version initializes.

### Per-call hint: `_bfb_format` on `$postarr`

Bypass the filter for a single insert by setting the `_bfb_format` key:

```php
wp_insert_post( array(
    'post_type'    => 'post',
    'post_content' => "# Markdown content here",
    '_bfb_format'  => 'markdown',
) );
```

### Version Registry

BFB supports multiple Composer consumers plus the standalone plugin in one request. Every loaded copy registers its
semantic version and source path. On `plugins_loaded:1`, BFB initializes one winner.

Registry rules:

- The highest semantic version wins.
- Released copies with distinct semantic versions are safe to load side-by-side.
- Duplicate same-version registrations from different sources emit a diagnostic with both source paths; the later
  registration wins deterministically.

The `dev-main` caveat: two different commits can report the same `$bfb_library_version`. Keep development consumers on
the same commit, or use distinct semantic versions when testing multiple copies together.

### Adapter registration

Third-party adapters can register at any point before they are looked up. Either eager-register on
`bfb_adapters_registered`:

```php
add_action( 'bfb_adapters_registered', function () {
    BFB_Adapter_Registry::register( new My_AsciiDoc_Adapter() );
} );
```

Or lazy-register via the lookup filter:

```php
add_filter( 'bfb_register_format_adapter', function ( $adapter, $slug ) {
    if ( $slug === 'asciidoc' && ! $adapter ) {
        return new My_AsciiDoc_Adapter();
    }
    return $adapter;
}, 10, 2 );
```

## Tests

Run the conversion smoke suite through [Homeboy](https://github.com/Extra-Chill/homeboy):

```bash
homeboy test block-format-bridge
```

The suite runs inside WordPress Playground and covers every documented `bfb_convert()` direction: HTML → Blocks,
Blocks → HTML, Markdown → HTML, Markdown → Blocks, Blocks → Markdown, and HTML → Markdown.

## License

GPL-2.0-or-later.
