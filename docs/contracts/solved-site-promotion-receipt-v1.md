# Solved Site Promotion Receipt v1

`static-site-importer/solved-site-promotion-receipt/v1` is the fail-closed decision proving that one immutable Static Site Importer and Blocks Engine candidate pair preserves every selected solved fixture.

The receipt is emitted only when:

- both candidates and the fixture tree use full Git commit identities;
- the selected and solved corpora are non-empty;
- every registry decision is `solved_candidate`;
- every import has a completed materialization receipt;
- every imported block is native and editor-valid through `wp.blocks.validateBlock`;
- source, imported, diff, and visual-diff artifacts exist with zero mismatch;
- all evidence files are content-hashed under the uploaded artifact root;
- reviewer-facing references resolve to the GitHub Actions run and artifact list.

SSI owns this decision and its schema. Homeboy may consume the receipt for generic finalization after validating the candidate and artifact identity chain; it does not reinterpret solved-site policy.
