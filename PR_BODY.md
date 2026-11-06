## Fix #2673

Keep validation scalars, failure reasons, all counts and diagnostic summary outside the shared inline detail budget. Large diagnostic/report lists still share the existing 200-item budget. Refresh cached fixture block counts from final materialization quality and preserve that canonical count in the receipt.

### Before/after receipt (two-page ZIP fixture)

Relevant `response.result` fields (before verdict reconstructed using the previous bounding algorithm):

```json
{"import_validation_result":{"schema":"blocks-engine/import-validation-result/v1","artifact_type":"ImportValidationResult","version":1,"status":"passed"}}
```

After (excerpt; the test compares every count and the entire diagnostic summary with the full report):

```json
{"import_validation_result":{"schema":"blocks-engine/import-validation-result/v1","artifact_type":"ImportValidationResult","version":1,"status":"passed","quality_pass":true,"fail_import":false,"failure_reasons":[],"counts":{"source_documents":2,"diagnostics":2},"diagnostic_summary":{"total":2,"error":0,"warning":0,"notice":0,"info":2}},"fixture_diagnostics":{"quality_counts":{"block_count":53}}}
```

The fixture materializes 53 blocks. Previously the cached compiler count could remain 0 (as in the issue's 87-block reproduction); the final receipt now agrees with the full report. The large case produces 502 diagnostics, retains the complete verdict, caps lists and satisfies the previous inline size allowance plus verdict fields.

### Verification

- Real CLI regression: `php tests/smoke-cli-receipt-verdict.php` passed using a temporary Node fetch-to-curl transport override because direct Node downloads timed out in this environment. No repository transport changes.
- `php tests/smoke-ability-import-success-diagnostics.php`: passed, 52 assertions.
- `homeboy review lint static-site-importer`: passed with no findings (run `5494ec7c-2642-4665-9bb6-94a183520ef4`). Original resource-admission failure did not recur on retry; no placement override used. Three alignment warnings were corrected.

AI disclosure: implemented with model `cook-heavy` (provider-reported model ID) using the pi coding agent.
