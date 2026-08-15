# Managed Classic Theme Materialization

`theme_materialization` is an explicit import option. Its default is `block`, preserving the existing editable block-theme projection.

`classic` is a managed classic-theme strategy. SSI derives render-neutral fragments from the already normalized artifact after the content and client-script policies run, then uses the compiler's canonical plan only for routes, asset destinations, WordPress entities, dependencies, and reconciliation. It does not reconstruct HTML from block markup.

Classic pages retain static runtime fallbacks while the established dependency/entity lifecycle still runs. Classic bindings resolve canonical artifact DOM selectors with exact occurrence/cardinality checks. The projection writes inert placeholder tokens to `classic-pages.json` and adapter-owned render records to `classic-bindings.json`; fixed scaffold PHP interprets only those records. Artifact-authored shortcode text is never executed.

The SSI-owned scaffold is deterministic: templates, `style.css`, `classic-pages.json`, `classic-chrome.json`, and `classic-bindings.json`. Its PHP is fixed reviewed SSI source. Page/chrome HTML is sanitized data, never interpolated into generated PHP and never passed to broad shortcode evaluation. Artifact URLs and CSS are canonicalized before scheme policy checks, and unsafe values fail closed.
