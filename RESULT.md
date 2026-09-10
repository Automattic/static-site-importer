# Provider Field Layout Result

## Change

When a source wrapper is a proven grid with exactly one mapped native field
spanning all of its tracks, SSI now omits only the grid's structural facts and
lets the full-width Jetpack field own that layout. The source placement may be
on an intermediate one-control wrapper rather than the control itself. Jetpack
adds label and error nodes, so that placement cannot be preserved there;
projecting only the tracks shrank each field to one track.

Unrelated parent layout facts remain eligible for the ordinary provider overlay.
Partial-span fields, multi-control rows, and fields with responsive placement
changes remain outside this rule.

## Verification

- `php tests/form-materializer-smoke.php` passed: 187 assertions.
- `npm run test:form-materializer-topology` passed: 4 tests.
- `npm test` passed: 81 selected tests, 0 failed.
- `npm run test:inventory` passed.
- Homeboy WordPress PHPCS passed on the changed PHP files.
- Homeboy WordPress PHPStan passed on the production seeder class. Its direct
  scan of the standalone smoke has existing class-symbol ordering findings;
  that smoke executes successfully.
- Full `homeboy review --placement local lint static-site-importer --path .`
  passed (run `64c40897-45c5-4a9b-8815-53c53203a626`): PHPCS, ESLint, and
  PHPStan all passed.

## Canonical Proof Status

- Paired development package built from SSI `534eee37` plus the scoped dirty
  diff and Blocks Engine `19de97b2`.
- Fresh Studio 3952 `create --from https://www.busybearscleaning.com` import
  completed at `http://localhost:8887` in 51 seconds.
- Chrome evidence at 1440px and 390px shows Contact input widths match the
  public source: `694.14px` desktop and `280px` mobile. The corresponding
  provider submit shell is `708.14px` desktop and `294px` mobile.
- No fatal browser errors or horizontal overflow were observed. Contact editor
  blocks are valid with no missing blocks; Jetpack marks the imported form post
  dirty on load, an existing provider-editor behavior.

## Draft PR

- PR: https://github.com/Automattic/static-site-importer/pull/1556
- Implementation commits: `4ace0c7c` and the pending nested-wrapper follow-up.
- Status: draft, pending CI for the proven Contact grid fix.
