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
JPEG, PNG, GIF, WebP, and AVIF. SVG, ICO, and other formats remain theme-owned
assets and are reported as unsupported for native attachment handoff. Relative
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

To exercise the owning block-template compiler candidate and both template
strategies in the same workload, add `SSI_TEMPLATE_COMPILER_ROOT=/path/to/blocks-engine/php-transformer`
after installing that checkout's Composer dependencies. Source and dependency
mounts stay inside WordPress's filesystem so secondary PHP requests see the
same overlay. The additional checks cover native post title/body, category
archives, empty queries, missing-route search recovery, shared chrome, and the
captured classic homepage. The runner prints its durable result/evidence path.
