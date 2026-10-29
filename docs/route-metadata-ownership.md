# Route metadata ownership

Refs #2430 (second slice; the issue remains open).

At materialization, the source document title becomes `post_title` (falling back
to the plan title when absent). A plain meta description becomes `post_excerpt`,
with `og:description`, then `twitter:description` as fallbacks. An explicit plan
excerpt remains the fallback when no document description exists. Text is decoded
and stripped of markup before storage.

An authored social image becomes `_thumbnail_id` referencing a Media Library
attachment. Explicit provider/plan featured-image handoffs take precedence. Both
already-routed v3 attachments and older theme-asset plans use the existing media
materializer and compensation journal. A declared image that cannot be resolved
to imported media fails materialization rather than retaining a source-host URL.

Registered Jetpack/WordPress.com SEO fields `jetpack_seo_html_title` and
`advanced_seo_description` receive the authored values, not generated content
summaries. Registration is checked for both global and post-type-specific fields;
absent fields are not invented. Jetpack uses the native featured image. Unknown
SEO plugin storage is not guessed; additional owner adapters are follow-up work.
SEO writes are verified and journaled for rollback.

The generated theme no longer installs route-title filters or source-head replay.
Core owns document-title formatting, including site-name suffixes. SSI's fallback
head projection reads current native post title, excerpt, permalink and thumbnail,
never source provenance, and steps aside for existing SEO emitters. It is plugin
code, not a second copy embedded in every theme. Deactivating SSI leaves all
native fields intact; core alone does not emit social metadata.

Site identity keeps its existing option owners (`blogname`, `blogdescription`,
`site_icon`). The staged public CLI now retains explicit identity evidence before
source script policy removes JSON-LD. Identity application also reuses v3 Media
Library resolutions rather than looking for bytes in retired theme assets.

## Theme-switch proof

Run `tools/run-route-ownership-acceptance.sh` with
`SSI_ROUTE_METADATA_EVIDENCE` pointing outside the checkout. It creates a disposable
Docker WordPress 7.1 site, imports a neutral two-route HTML/PNG fixture through the
public CLI, repeats the import, injects a late rollback, and switches to the core
Twenty Twenty-Five block theme. It compares native content, routes, navigation
posts, media hashes/URLs, featured images, excerpts, registered SEO fields and site
options. Fresh anonymous HTTP requests verify rendered content, singleton metadata,
site icons, public media and native alias redirects before and after switching.
The platform-field fixture registers the actual Jetpack storage contract; it does
not simulate Jetpack's head emitter or claim full platform integration coverage.

**Recorded presentation-only decisions:** source CSS, layout, templates and
header/menu placement belong to the imported theme and intentionally change on
switch. `wp_navigation` posts and their core block rendering remain available;
new themes choose their own menu placement. Language option persistence is checked,
but importing source language into `WPLANG` is a remaining slice.

## Remaining slices

- Site-language materialization and locale-aware output.
- Additional platform SEO adapters and full platform singleton-head coverage.
- Broader permalink/redirect and export/retirement coverage across source types.
- Global Styles/colour-scheme contrast ownership.
- Retirement of public verification-only scripts.
