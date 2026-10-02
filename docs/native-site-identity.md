# Native site identity evidence

SSI reads branding only from the ordinary artifact entrypoint. Supported source
evidence is an explicit `Organization` or `WebSite` JSON-LD `logo` URL and
`slogan`, explicit `rel="icon"` / `rel="apple-touch-icon"` links, and a linked
web app manifest. Callers may provide `site_tagline`; it takes precedence over
an explicit JSON-LD slogan. Generic description metadata and headings are not
tagline evidence.

Branding changes are authorized only when SSI activates its generated theme.
Existing owner values are preserved. The site icon uses WordPress `site_icon`;
the tagline uses `blogdescription`. Theme templates and captured header markup
remain untouched. Native Logo attachment handoff uses WordPress core's global
`site_logo` contract where supported; captured chrome is not rewritten to force
that logo visually.

Raster attachment formats supported by the existing media materializer are
JPEG, PNG, GIF, WebP, and AVIF. Explicit SVG branding also becomes native logo
and site-icon attachments when the owning compiler verifies self-contained
passive artwork and resolves its intrinsic dimensions. SVG keeps its exact vector
bytes, local gradients/definitions, and viewBox-derived attachment metadata.
The import scopes SVG MIME admission to the validated upload; ordinary upload
policy remains unchanged. Stylesheet-dependent, active/external, malformed, and
dimensionless SVGs retain explicit receipt statuses instead of guessed output.
Verified ICO branding becomes native logo/site-icon attachments with core's
`image/x-icon` MIME, largest-frame intrinsic dimensions, and exact container
bytes. PNG-backed frames and uncompressed 1-, 4-, 8-, 24-, and 32-bit DIB frames
are inspected without converting or reconstructing pixels. Inspection bounds
the input to 2 MiB and 64 entries, checks directory/payload ranges and dimensions,
PNG framing/CRC/scanline sizes, and DIB palette/pixel/mask bounds. Malformed ICOs
report `invalid_ico`; unsupported encodings report `unsupported_ico`, with a
specific reason. The original theme asset remains available.
Other unsupported formats remain theme-owned assets with explicit receipts.
SVG favicon markup is emitted by core; platform
support for SVG touch icons varies and this handoff does not create PNG derivatives. Relative
resources must resolve to canonical artifact writes. SSI does not fetch network
resources during application. Missing, unresolved, and unsupported evidence is
reported rather than guessed.

## Application and receipts

Entrypoint-relative and root-relative URIs resolve inside the artifact root;
manifest icon URIs resolve against the manifest document. Canonical resolved
asset writes supply the final theme path. The existing Media Library materializer
creates or reuses attachments, so identical logo/icon bytes share an attachment.

Native branding is applied after generated-theme activation. `site_logo` uses
WordPress core's existing global setting and custom-logo filter, `site_icon` uses
the native option, and an explicit slogan seeds `blogdescription`. Existing
owner-selected values remain authoritative. Preview and existing-theme imports
do not apply global branding. Every changed option is journaled and verified.
Rollback also protects the old theme's theme-mod option from core's site-logo
deletion side effects and removes newly created attachments.

The materialization receipt exposes `completed.site_identity` with per-setting
statuses such as `applied`, `preserved_owner_value`, `absent`, `unresolved_asset`,
`unsupported_format`, `unresolved_manifest`, and `unknown_evidence`.

## Disposable WordPress acceptance

With the native WP Codebox CLI installed, from the SSI checkout:

```sh
WP_CODEBOX_CLI=/path/to/wp-codebox/packages/cli/dist/index.js \
node tests/acceptance/run-native-site-identity.mjs
```

This boots disposable WordPress, checks real attachment IDs, native logo/icon
rendering, shared image ownership, reimport deduplication, preservation, and
rollback. It does not modify the host site. The fixture requires its explicitly
declared disposable-test constant.

The ICO assertions additionally check actual attachment MIME and largest-frame
metadata, exact uploaded bytes, core custom-logo and site-logo block rendering,
and core favicon markup/URLs, using a 16px-first/32px-second PNG-backed ICO.
Logo and favicon share one attachment; reimport
after clearing both settings must reuse it. Distinct owner-selected attachments
and an owner slogan must survive an activated import. Preview and existing-theme
imports are checked with empty branding settings so preservation cannot mask an
unauthorized write; they must leave global options and active-theme files intact.
An existing-theme import explicitly requesting activation must be rejected.
A unique, valid ICO in a post-identity injected failure exercises rollback of
new attachments, uploads, theme/companion files, options, and old theme mods.
Option snapshots compare WordPress's persisted representation rather than
request-cache scalar types. Filesystem snapshots cover actual theme/plugin/upload
bytes; database rollback is checked through logical options and attachment rows,
not by expecting the runtime's physical SQLite storage bytes to remain identical.

Native rendering is not an image-editor guarantee. In the disposable WordPress
runtime, ICO cropping is expected to return the observed core `image_no_editor`
error. The oracle calls `wp_crop_image()` and checks that error and unchanged
source bytes; it does not mock an editor, convert the ICO to PNG, or claim that
core creates resized ICO derivatives. Requested favicon sizes retain the
original ICO attachment URL. A runtime without native ICO handoff fails these
assertions rather than silently skipping them.

Browser decoding/transparency is independently verified with:

```sh
node tests/acceptance/ico-browser.mjs
```

Chromium decodes every RGBA pixel for PNG-backed multiframe and 16/32/256px ICOs,
plus padded, bottom-up 1/4/8-bit palette DIBs and their AND transparency masks.
The script retains the input ICOs, decoded PNGs, screenshot, and result JSON.

To exercise the owning block-template compiler candidate and both template
strategies in the same workload, add `SSI_TEMPLATE_COMPILER_ROOT=/path/to/blocks-engine/php-transformer`
after installing that checkout's Composer dependencies. Source and dependency
mounts stay inside WordPress's filesystem so secondary PHP requests see the
same overlay. The additional checks cover native post title/body, category
archives, empty queries, missing-route search recovery, shared chrome, and the
captured classic homepage. The runner prints its durable result/evidence path.
