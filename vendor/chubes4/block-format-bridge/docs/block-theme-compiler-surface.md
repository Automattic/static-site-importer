# Block Theme Compiler Consumer Surface

Issue: https://github.com/chubes4/block-format-bridge/issues/29

This note defines the Block Format Bridge surface a future static HTML/CSS to block-theme compiler should consume. It is intentionally limited to BFB's boundary: format conversion through the block pivot. Block-theme structure, Site Editor behavior, template intent, theme.json generation, and per-block transform behavior belong above BFB or inside html-to-blocks-converter.

## Boundary

BFB is the substrate between content formats and WordPress block data:

```
HTML / Markdown / future formats
        |
        v
  BFB format adapter
        |
        v
 WordPress block arrays
        |
        +--> serialize_blocks()  -> block markup
        +--> render_block()      -> HTML
        +--> markdown adapter    -> Markdown
```

A site compiler can use BFB for deterministic conversion once it has already decided what belongs in templates, template parts, patterns, posts, or global styles.

## Explicit Site Editor Primitive Markers

BFB's public marker vocabulary is intentionally narrow and explicit:

- `data-bfb-pattern="namespace/slug"` declares a WordPress pattern reference.
- `data-bfb-template-part="area-or-slug"` declares a template part reference.

These markers exist for compiler output, not heuristic discovery. A compiler may emit them after it has already decided
that a fragment should become a pattern reference or template part. BFB/h2bc must not infer the same primitives from
ordinary wrappers such as `<header>`, `<footer>`, `<section class="hero">`, or a repeated layout shape.

The deterministic targets are:

- Pattern marker → `core/pattern` with the marker value as `slug`.
- Template-part marker → `core/template-part` with the marker value as `slug`, and `area` when the value is a standard Site Editor area such as `header`, `footer`, or `sidebar`.

`data-wp-*` aliases are out of scope unless WordPress itself defines them as source-HTML markers. BFB-owned attributes
avoid implying a WordPress core contract that does not exist.

Implementation note: the marker contract is documented here because BFB owns the public conversion substrate. The actual
HTML-element transforms currently live in html-to-blocks-converter, the library BFB delegates to for HTML → Blocks. That
is a shared extension contract: h2bc may recognize these explicitly documented BFB markers, while BFB verifies marker
behavior through `bfb_convert( $html, 'html', 'blocks' )` instead of adding a parallel pre-parser around h2bc.

## Answers

### 1. First-class block-array helper

BFB exposes `bfb_to_blocks( string $content, string $from ): array`.

Today callers can already get block arrays with `bfb_get_adapter( $from )->to_blocks( $content )`, but that reaches through the public registry into the adapter contract. Compiler code should not need to know whether `'blocks'` is a special source, whether an adapter exists, or whether the input should be parsed with `parse_blocks()`.

Contract:

```php
/**
 * Convert content into parse_blocks()-compatible block arrays.
 *
 * @return array<int, array<string, mixed>> Empty array on unsupported source or conversion failure.
 */
function bfb_to_blocks( string $content, string $from ): array;
```

Behavior:

- `from === 'blocks'` parses serialized block markup with `parse_blocks()`.
- Other formats resolve through `bfb_get_adapter( $from )` and call `to_blocks()`.
- Unsupported formats return an empty array and log the same style of error as `bfb_convert()`.
- The helper should be the internal source of truth for `bfb_convert()` once implemented, avoiding two conversion paths.

Use this helper instead of reaching into adapters directly.

### 2. WP-CLI conversion command

BFB exposes a CLI command for non-PHP callers.

Node-based tools such as Studio can already call WordPress through WP-CLI. A command like this would let those tools use the server-side converter without embedding PHP glue:

```bash
wp bfb convert --from=html --to=blocks < input.html
```

Command shape:

- `wp bfb convert --from=<format> --to=<format> [--input=<file>] [--output=<file>]`
- Read STDIN when `--input` is omitted.
- Write STDOUT when `--output` is omitted.
- Return serialized content for normal format targets.
- Use a separate flag for structured block arrays, for example `--as=json`, rather than overloading the default output.

The CLI is a thin wrapper around `bfb_convert()` for serialized output and `bfb_to_blocks()` for `--as=json` structured block output.

### 3. Arrays plus serialized markup in one call

BFB should support this as a convenience for compiler workflows, but keep it separate from `bfb_convert()`.

Compiler consumers often need both representations:

- Block arrays for inspection, splitting, policy checks, and template/pattern assembly.
- Serialized block markup for writing to WordPress files or `post_content`.

Recommended future helper:

```php
/**
 * Convert content to block arrays and serialized block markup in one pass.
 *
 * @return array{blocks: array<int, array<string, mixed>>, markup: string}
 */
function bfb_to_block_document( string $content, string $from ): array;
```

Implementation should compose the first-class helper:

```php
$blocks = bfb_to_blocks( $content, $from );
return array(
    'blocks' => $blocks,
    'markup' => serialize_blocks( $blocks ),
);
```

This avoids double conversion without changing `bfb_convert()` from a string-returning universal converter.

### 4. Metadata preservation contract

BFB should document the metadata contract, but the transform details stay in html-to-blocks-converter.

For HTML to Blocks, BFB's stable contract should be:

- BFB returns WordPress block arrays compatible with `serialize_blocks()` and `parse_blocks()`.
- Source `class` attributes that html-to-blocks-converter maps into block attributes remain in `attrs` and are serialized by WordPress core.
- Source `style` attributes that html-to-blocks-converter maps into block attributes remain in `attrs` and are serialized by WordPress core.
- Source anchors / IDs that html-to-blocks-converter maps into block attributes remain in `attrs` and are serialized by WordPress core.
- BFB does not promise semantic inference beyond the attributes emitted by the active adapter.
- Per-block mapping rules and fidelity fixes belong in html-to-blocks-converter tests and releases.

That gives compiler consumers a stable integration target without moving transform ownership into BFB.

## Recommended Sequence

1. Document this surface now.
2. Use `bfb_to_blocks()` when a compiler consumer needs block arrays directly.
3. Add `bfb_to_block_document()` if callers repeatedly need arrays plus serialized markup from the same source.
4. Use `wp bfb convert` for non-PHP callers that need server-side conversion.
