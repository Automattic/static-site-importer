# Native source redirects

SSI preserves supported source URLs directly. When captured `_redirects` aliases or canonical whole-page handoffs require a different native destination, the `redirects` provider prepares Redirection and binds exact-path 301 rules to committed page IDs and their real permalinks. A canonical `dependency` declaration with `capability: redirects` can also request provider readiness. Imports without redirect intent install no redirect plugin.

The provider uses Redirection's local REST API for database setup, groups, rules, and enabled state. Default selection is `redirection`; hosts can select a registered provider through `static_site_importer_redirects_plugin` or `ssi_redirects_plugin`. Unavailable providers produce an explicit required-dependency failure.

Authorization follows the import. Web requests call the provider as their authenticated user, so Redirection's own capability checks still apply. WP-CLI imports are operator-authorized and may run without `--user`, as Studio does; for those, SSI satisfies Redirection's `redirection_capability_check` filter only for the duration of each provider call it makes. Direct provider calls outside SSI keep their normal checks.

## Ownership and reconciliation

- Rules live in a source-scope group and remain editable in **Tools → Redirection**.
- Exact source paths redirect permanently to final native destinations with query passthrough. Projection is bounded to 500 rules; native collision inspection is bounded to 2,000 rules.
- Reimport preserves IDs and rejects owner-created or owner-edited collisions, occupied published routes, ambiguous destinations, and redirect chains.
- Removed aliases disable owned rules while retaining IDs and history. Mutation journals restore prior rule configuration and enabled state, remove newly created rules/groups, and verify native readback and ownership restoration.
- SSI's fallback route resolver defers to native-owned paths, including rules that an owner disables or removes.

## Lifecycle and export

Registered providers can declare `materialization_stage: after_pages` to consume the committed page receipt. The default stage is `before_pages`; unsupported stages are rejected. Late failure rolls back page materialization and compensates journaled provider mutations. Failed and partial reports remain eligible for compensation when they contain mutation evidence.

Provider `export_callback` hooks add portable runtime state to the shared website artifact. Redirection exports active owned rules to `_redirects`, bound to actual exported target documents, and includes its dependency declaration. Owner destination edits are retained when the target is an exported native permalink. Matcher changes, non-301 actions, query/fragment target changes, and unexported destinations return explicit errors rather than silently losing behavior. Disabled or removed rules contribute no alias.

## Disposable acceptance

Run `bash tools/run-redirection-acceptance.sh /absolute/checkout` with Docker, Node, and Playwright available. Set `SSI_REDIRECTION_PLAYWRIGHT_MODULE` to an absolute Playwright module path when it is supplied by the caller, and `PLAYWRIGHT_BROWSERS_PATH` for a caller-owned browser cache.

The harness creates two disposable WordPress sites and a complete exported-artifact server. Evidence in `artifacts/redirection` covers native installation by an identity-less CLI operator, refusal of unauthenticated provider and import callers, idempotence, retirement, rollback, denied ownership writes, committed-write/readback failure, false-success deletion responses, owner conflicts, actual GET/HEAD/query behavior, a producer-generated TEC route, native admin visibility, served export, and second-site navigation/reload. The acceptance scope is redirect behavior and portability; visual parity is evaluated separately.
