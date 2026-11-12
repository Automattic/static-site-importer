# Existing-theme imports

Follow-up to [#2810](https://github.com/Automattic/static-site-importer/issues/2810) and [#1617](https://github.com/Automattic/static-site-importer/issues/1617).

Pass `destination: "existing_theme"` to `static-site-importer/import` (including REST and CLI request files). The default remains `generated_theme`. Saved plans retain the destination. Existing-theme imports never activate a theme. Importer-generated hosts can extend their importer-owned runtime; other hosts never install dependency/registration files. Theme, plugin and mu-plugin writes are withheld with reviewable diagnostics; source images, including CSS raster backgrounds, use Media Library uploads instead.

Each page gets one root `core/group` with a stable import/page-derived class. Stylesheet occurrences are replayed in source order, including repeated sheets and link media conditions. CSS lives in the active theme's user Global Styles `styles.css`, not block custom CSS: core's block custom CSS parser does not preserve conditional group rules. Core emits Global Styles CSS in both frontend and editor, without importer runtime code.

One marker-delimited section per import slug owns the CSS. Re-import replaces that section, preserving surrounding user CSS and settings. Different import slugs own independent sections. `Static_Site_Importer_Existing_Theme_Styles::remove( $section_id )` removes that section and pages tagged with its ownership metadata; the section ID is reported in the materialization receipt's `completed.global_styles`. Transaction rollback restores the prior section (or removes a newly created section).

## Deliberate limits

- Global Styles belong to a theme. Styling does not follow a theme switch; plan and receipt report this limitation.
- Applying CSS requires `edit_css`. Plan reports a missing capability and apply fails closed.
- Only conditional `@media`, `@supports`, and `@container` group rules are carried. Other at-rules, including `@import`, `@font-face`, keyframes, layers and global property registrations, are omitted with diagnostics. Fonts are not published as theme/plugin files.
- Nested style rules and root sibling selectors are omitted with diagnostics rather than risking host-theme leakage. Ordinary element/universal selectors are page-contained; document root aliases target the imported root.
- CSS URLs not materialized to uploads are replaced with empty data URLs and diagnosed. Unsupported fonts and resources do not silently refer to withheld theme files.
- Importer-generated hosts are detected from their run manifest and `ssi-runtime/companion.json` ownership. Their block namespace is reused; missing block directories are journaled into `ssi-runtime/` only. Different bytes in an existing directory fail closed with `existing_theme_runtime_block_conflict`. Per-page diagnostics distinguish reused, added, and unavailable types.
- Other hosts still reject pages needing unregistered generated typed blocks. Already registered destination blocks can be used.
- Page selection, remapped slugs, draft/standalone choices and independent token resolution are not part of this slice.

## Tests

- `tests/smoke-existing-theme-global-styles.php` (`wordpress-runtime`): end-to-end through the public ability on a disposable WordPress with Twenty Twenty-Five.
- `tests/smoke-existing-theme-css.php` (`standalone-php`): selector containment, conditional rules, omitted constructs, and section ownership.
