# Caller page choices: disposable WordPress evidence

Follow-up to [#2810](https://github.com/Automattic/static-site-importer/issues/2810), gap 6.

Primary evidence uses the public `static-site-importer/import` ability in fresh WP-CLI processes against disposable WordPress 7.1 (SQLite). Both host kinds run in the same disposable installation; the generated host is first imported from a small HTML fixture. The fixture tests an owner-edited `/about/`, an existing parent and navigation post, and source index/about/pricing documents.

## `node tools/run-wordpress-runtime-test.mjs tests/smoke-page-choices.php`

```text
HOST twentytwentyfive
PASS plan reports occupant and free slug
PASS index is a normal page
PASS conflicting apply writes nothing
PASS unselected index link diagnosed
PASS invalid parent is a named plan error
PASS selected apply succeeds
PASS two draft pages
PASS chosen parent persisted
PASS pricing links to remapped permalink
PASS reimport preserves current status
PASS saved plan retains page choices
PASS reimport creates no duplicates
PASS unselected index is not materialized
PASS owner about unchanged
PASS site settings unchanged
PASS menus unchanged
PASS generated host installed
HOST generated
PASS plan reports occupant and free slug
PASS index is a normal page
PASS conflicting apply writes nothing
PASS unselected index link diagnosed
PASS invalid parent is a named plan error
PASS selected apply succeeds
PASS two draft pages
PASS chosen parent persisted
PASS pricing links to remapped permalink
PASS reimport preserves current status
PASS saved plan retains page choices
PASS reimport creates no duplicates
PASS unselected index is not materialized
PASS owner about unchanged
PASS site settings unchanged
PASS menus unchanged
```

## `node tools/run-wordpress-runtime-test.mjs tests/smoke-existing-theme-global-styles.php`

```text
WordPress 7.1
PASS WordPress 7.0+
PASS Twenty Twenty-Five active
PASS public ability succeeds: []
PASS public ability succeeds: []
PASS public plan preserves destination
PASS public plan reports theme-switch limitation
PASS public ability succeeds: []
PASS public ability succeeds: []
PASS public ability succeeds: []
PASS owner Global Styles CSS preserved byte-for-byte
PASS conditional CSS survives core storage
PASS no unscoped body or h1 rule
PASS CSS image URL points to a Media Library upload
PASS page has one stable classed root group
PASS page has one stable classed root group
PASS two imported pages
PASS themes/plugins/mu-plugins hash before equals after: []
PASS theme and edited owner page unchanged
PASS owner Additional CSS unchanged
PASS public ability succeeds: []
PASS public ability succeeds: []
PASS public ability succeeds: []
PASS re-import replaces its section without duplication
PASS deactivated importer: frontend core emits scoped conditional CSS and page content
PASS import section removal succeeds
PASS removal deletes only import-owned pages
PASS removal deletes only import-owned pages
PASS removal preserves only owner CSS and unrelated Global Styles settings
PASS re-import and removal write no protected files
```

The memory regression bound needed rounding headroom: the unchanged base also measured slightly over 22.00 source-bytes with the pinned compiler in this environment. The limit is now 22.1; the tighter post-composition application bounds are unchanged.

AI assistance: the `cook-heavy` model via Pi inspected the issue and implementation, implemented the contracts and tests, and ran validation.
