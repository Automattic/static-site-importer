# Provider Field Layout Result

## Change

When a source wrapper is a proven grid with exactly one mapped native field
spanning all of its tracks, SSI now omits only the grid's structural facts and
lets the full-width Jetpack field own that layout. Jetpack adds intermediate
label and error nodes, so the original direct-child grid placement cannot be
preserved there; projecting only the tracks shrank each field to one track.

Unrelated parent layout facts remain eligible for the ordinary provider overlay.
Partial-span fields, multi-control rows, and fields with responsive placement
changes remain outside this rule.

## Verification

- `php tests/form-materializer-smoke.php` passed: 186 assertions.
- `npm run test:form-materializer-topology` passed: 4 tests.
- `npm test` passed: 81 selected tests, 0 failed.
- `npm run test:inventory` passed.
- Homeboy WordPress PHPCS passed on the changed PHP files.
- Homeboy WordPress PHPStan passed on the production seeder class. Its direct
  scan of the standalone smoke has existing class-symbol ordering findings;
  that smoke executes successfully.
- Full `homeboy review --placement local lint static-site-importer --path .`
  passed (run `38ac8a4e-bc19-4a4f-9b4f-ed22dadbd0d7`): PHPCS, ESLint, and
  PHPStan all passed.

## Canonical Proof Status

The required paired development package could not be built yet. Homeboy requires
150 GiB free before packaging; the temporary filesystem has 141.7 GiB available,
an 8.9 GiB shortfall. Its scoped artifact-cleanup inventory timed out without
listing candidates. No cleanup was applied.

The fresh Studio 3952 public-source import and browser screenshots remain pending
until sufficient capacity is made available.
