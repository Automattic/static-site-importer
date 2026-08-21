# Solved Site Promotion Receipt v1

`static-site-importer/solved-site-promotion-receipt/v1` is the fail-closed decision proving that one immutable Static Site Importer and Blocks Engine candidate pair preserves every selected solved fixture with a pinned WP Codebox evidence provider.

The receipt is emitted only when:

- both transformation candidates, the WP Codebox evidence provider, and the fixture tree use full Git commit identities;
- the selected and solved corpora are non-empty;
- every registry decision is `solved_candidate`;
- every import has a completed materialization receipt;
- every imported block is native and editor-valid through WP Codebox's loaded-post `wp.blocks.validateBlock` browser artifact, with registered block types and one complete recursive result per block;
- source, imported, diff, and visual-diff artifacts exist with zero mismatch;
- all evidence files are content-hashed under the uploaded artifact root;
- the hashed runtime-input artifact binds the WP Codebox release version and package checksum to its release commit;
- reviewer-facing references resolve to the GitHub Actions run and artifact list.

SSI owns this decision and its schema. Homeboy may consume the receipt for generic finalization after validating the candidate and artifact identity chain; it does not reinterpret solved-site policy.

## Downstream build status

Receipt status `accepted` records the solved-site promotion decision. It does not deploy or mark a downstream site as built. Downstream finalization owns setting `status:built` after validating this receipt and the candidate identity chain. Finalization must not reinterpret solved-site policy or replace this receipt with a separate acceptance decision.

## Required branch-protection check

The `solved-site-promotion` job must be configured as a required status check on the protected target branch before any post-acceptance push or downstream `status:built` transition. This workflow cannot enforce branch protection itself. Receipt validity depends on repository branch protection requiring this check for the relevant candidate ref.
