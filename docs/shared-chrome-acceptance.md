# Shared chrome producer/consumer acceptance

The paired contract makes Blocks Engine the owner of navigation equivalence and
exact occurrences. SSI persists `wp_navigation` entities and binds explicit refs.
The producer declares `reference_semantics.navigation_entities: explicit_refs/v1`.
Plans with legacy implicit entity binding require recompilation with the paired
producer; the consumer does not infer ownership from labels or URLs.

Nested shared header/footer content is editable once through template parts while
references stay within each page's source layout. Native disclosures retain their
own panels. Genuine destination, hierarchy or item-presentation differences stay
distinct; JSON escape spelling and object key order are transport differences.

## Disposable runtime proof

The acceptance runner owns one throwaway Docker project and mounts the selected
producer and consumer checkouts read-only. It installs a supported WordPress
version (nightly by default), imports the neutral two-route fixture through the
canonical CLI, and records evidence outside the checkouts.

Prerequisites: Docker, Composer dependencies, Node dependencies and Playwright
Chromium. Run on a Docker-capable Lab runner:

```bash
SSI_SHARED_CHROME_BLOCKS_ENGINE_PATH=/path/to/blocks-engine-candidate \
SSI_SHARED_CHROME_EVIDENCE=/path/to/evidence \
bash tools/run-shared-chrome-acceptance.sh
```

`SSI_SHARED_CHROME_WORDPRESS_VERSION` selects an explicit Core version;
`SSI_SHARED_CHROME_PORT` selects the loopback HTTP port. The dependency overlay
is confined to the disposable runtime. Production Composer pins stay unchanged
until a coordinated upstream release is selected.

Acceptance requires:

- zero fallback blocks and exact viewport pixels at 390, 768 and 1440 pixels;
- header/footer rendered beneath their original layout parent;
- the actual native summary opens and closes its own descendant menu;
- real Gutenberg `validateBlock` checks, editor data-store menu/footer saves,
  a fresh editor reload, and shared edits rendered across both routes;
- a late-failing materialization updates the actual existing navigation entities
  and restores escaped owner-edited content exactly;
- a second canonical import completes and retains the same navigation IDs.

The runner retains source/imported/difference PNGs, rendered HTML, `browser.json`,
import reports, runtime inventory, rollback and reimport receipts, Core version
and Apache logs. Source snapshot identities and the Homeboy runner execution ID
bind the evidence to the exact dirty candidate trees. This neutral fixture proves
these contracts; real-site acceptance remains tied to each site's own corpus.
