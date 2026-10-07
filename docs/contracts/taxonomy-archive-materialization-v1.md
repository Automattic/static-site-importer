# Taxonomy Archive Materialization

SSI consumes the Blocks Engine `taxonomy_entities` rows; it does not infer a
term from a route. The engine row binds a native taxonomy and term to canonical
post `source_path` identities and a captured archive source route. Engine
evidence is required before persistence.

During preflight, a proven archive source document is retained in the receipt
but excluded from page writes and page-content validation. Its source route is
owned by the native term archive, not a static page. Generated-theme imports
retain the archive's presentation in the engine-authored
`category-{slug}.html` template, where captured listing cards use an inherited
Query Loop.

Persistence reuses a native term with the same taxonomy and slug without
changing its name. It creates a missing term, adds proven relationships only to
the canonical imported posts, and stores the term IDs SSI itself assigned in
`_static_site_importer_taxonomy_memberships`. Reimport removes prior SSI-owned
memberships that are no longer source-proven while preserving other categories
and tags. Exact source archive rewrites are registered for proven archive
routes; SSI does not change the site's global category or tag base. The
generated theme bootstrap retains those exact rewrite rules and term-link
projection after the import plugin is removed.

The mutation journal restores changed memberships and SSI ownership metadata,
deletes only terms created by the failed import, and restores rewrite rules on
rollback. Internal links to captured archive routes resolve to the
native term permalink. Posts added or recategorized in WordPress are reflected
by the inherited archive query without editing the captured archive page.
