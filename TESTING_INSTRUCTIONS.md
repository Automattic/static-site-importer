# Testing Instructions: Restore Repository Lint Gate (#750)

## What Changed

1. Restored `review lint` alongside `review test` in the Homeboy CI matrix.
2. Applied PHPCBF to current production PHP, resolving 4,021 mechanical findings introduced before the gate was restored.
3. Recorded the remaining 237 findings in Homeboy's repository baseline so unchanged debt passes and new findings fail the gate.
4. Removed the stale PHPStan baseline entry for the deleted transformer adapter.
5. Dropped the unsupported second argument to `ArtifactCompiler::compile()` while retaining `compiler_options` as an explicitly diagnosed compatibility no-op.
6. Preserved the resumable import and SVG font behavior merged after the contributor branch was opened.

## Verification

```bash
homeboy review lint --summary
npm test
for file in static-site-importer.php includes/*.php; do php -l "$file" >/dev/null || exit 1; done
```

Expected results:

- Homeboy lint passes with no drift from the 237-finding baseline.
- The test manifest passes 42 selected checks: 34 standalone PHP and eight Node lanes.
- Every production PHP file passes syntax validation.

## Ratcheting

When existing findings are repaired, run `homeboy review lint --ratchet` to remove resolved fingerprints from `homeboy.json`. New findings fail CI without requiring the existing debt to be repaired in the same change.
