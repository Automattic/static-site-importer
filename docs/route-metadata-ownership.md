# Route metadata ownership

Refs #2430 (the issue remains open).

At materialization, `post_title` stays the plan's route title: the name page lists,
menus and the editor show. The source document title (which usually carries a
brand suffix) is recorded in provenance and written to a registered SEO title
field, so core never renders a doubled site name. A plain meta description becomes `post_excerpt`,
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

## Language ownership

The source document language becomes the site language owner. The entrypoint's
`<html lang>` (falling back to a `content-language` meta) resolves to a WordPress
locale — known bare tags map to their locale (`nl` → `nl_NL`), explicit regions
win (`pt-BR` → `pt_BR`), and an unresolvable tag is recorded, never applied. The
resolved locale installs through the core language-pack path
(`wp_download_language_pack`); a pack that cannot be installed keeps the site
default and records an owner-handoff decision with a reason — it never fails the
import. A language the site owner already set (`WPLANG`) is recorded as
preserved, never overwritten. The write is journaled for rollback.

Route-level languages that differ from the effective site language are recorded
on the route's own owner (post meta `_static_site_importer_route_language`) so a
later multilingual owner can claim them without re-parsing sources. Matching
routes stay unwritten.

The composed theme runtime no longer replays the source `lang` attribute into
`<html>`; the appended ownership section strips `lang` from the replayed document
roots so core's `language_attributes()` — fed by the site language owner — is the
sole emitter. Core formats (e.g. `nl-NL`) replace bare source tags on every
rendered route.

## Verification-only script retirement

The font-readiness probe is verification machinery, not site payload. Generated
themes enqueue it only when the request carries the verification signal
(`?static_site_importer_verify=font-readiness`); logged-out public routes never
render it. The fixture-matrix visual-parity candidate capture sends that signal
so parity evidence still waits on the readiness record, and the theme-switch
acceptance proves both worlds on real HTTP: public HTML without the probe, a
signal-requested capture with it.

## Theme-switch proof

Run `tools/run-route-ownership-acceptance.sh` with
`SSI_ROUTE_METADATA_EVIDENCE` pointing outside the checkout. It creates a disposable
Docker WordPress 7.1 site, imports a neutral three-route multilingual HTML/PNG
fixture (Dutch entrypoint, English and French routes) through the public CLI,
repeats the import after the site owner picks a different language, injects a
late rollback, and switches to the core Twenty Twenty-Five block theme. It
compares native content, routes, navigation posts, media hashes/URLs, featured
images, excerpts, registered SEO fields and site options. The first import
installs the source language through the core language-pack path; the owner's
language then survives reimport, rollback and the switch. Route languages that
differ from the site language persist on their post owners. Fresh anonymous HTTP
requests verify rendered content, singleton metadata, site icons, public media
and native alias redirects before and after switching, that exactly one `lang`
owner emits `<html lang>` in the core form, and that the font-readiness probe
renders only on a verification-signal request, never on the public front end.
The platform-field fixture registers the actual Jetpack storage contract; it does
not simulate Jetpack's head emitter or claim full platform integration coverage.

**Recorded presentation-only decisions:** source CSS, layout, templates and
header/menu placement belong to the imported theme and intentionally change on
switch. `wp_navigation` posts and their core block rendering remain available;
new themes choose their own menu placement.

## Remaining slices

- Additional platform SEO adapters and full platform singleton-head coverage.
- Locale-aware output beyond the site language owner (route-level language
  rendering, hreflang/emitters for recorded route languages).
- Broader permalink/redirect and export/retirement coverage across source types.
- Global Styles/colour-scheme contrast ownership.
