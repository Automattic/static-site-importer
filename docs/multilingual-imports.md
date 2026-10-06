# Declared multilingual imports

SSI provisions TranslatePress through its ordinary provider dependency registry when a canonical runtime declaration requests the `multilingual` capability. Imports with no declaration install no multilingual plugin. Provider selection uses `static_site_importer_multilingual_plugin` and `ssi_multilingual_plugin`.

For dependency preparation alone, declare `kind: dependency`, `capability: multilingual`, and the canonical `source_path` in `source.metadata.runtime_declarations`.

For native language configuration, declare one `entity_collection` of type `multilingual` with payload schema `generic/multilingual/v1` and one entity:

```json
{
  "id": "site-languages",
  "default_language": "en_US",
  "languages": ["en_US", "fr_FR"]
}
```

Locale codes are checked against the installed provider's supported language service. The default free-provider contract supports two languages. The canonical CLI prepares the plugin and uses its existing fresh-runtime continuation before applying settings. Native activation hooks execute in a scoped headless context so interactive onboarding redirects cannot terminate the import worker.

The adapter writes native language and URL-slug settings, preserving other provider preferences and switcher styling. Initial TranslatePress defaults provide a visible native floating language selector. Explicit owner changes are protected; identical owner settings are reused without claiming ownership. Reimport is idempotent, and compensation restores exact importer-owned option changes. Owner-edited switcher visibility is preserved.

This provisions the multilingual runtime and translation management. It does not invent translations or claim migration of a source translation dictionary. Source recognition and translation-content extraction remain producer-owned contracts.

Run `bash tools/run-multilingual-acceptance.sh` on a Docker-capable host for actual installation through the canonical CLI, native settings, reimport, rollback, owner conflicts, and Playwright selector/navigation/reload proof at 1440 and 390 pixels. A relocated harness can set `SSI_MULTILINGUAL_PLAYWRIGHT_MODULE` to an installed Playwright entrypoint and `PLAYWRIGHT_BROWSERS_PATH` to its browser cache. The harness uses and removes only its own disposable containers, network and WordPress volume.
