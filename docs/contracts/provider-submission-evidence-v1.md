# Provider Submission Evidence v1

`static-site-importer/provider-submission-evidence/v1` is the fail-closed receipt proving that a materialized form accepted a valid payload in the target WordPress runtime and stored a local provider record.

SSI owns this decision. Provider adapters own save shapes and submission APIs. The evidence never treats pointer interactivity, browser required-field styling, or notification delivery as proof that leads were received.

## Required proof

Accepted evidence binds one form to the exact page path, form identity, provider id, provider version, plan hash, and completed materialization receipt. It then proves:

- the submission request reached a WordPress-owned endpoint, not a retained source backend;
- a required-field payload failed without creating a local receipt;
- a valid payload created a deterministic local receipt such as a `feedback` entity;
- a provider failure returned failure UI without a local receipt;
- a duplicate valid submit stayed bounded to the local receipt;
- notification capability is recorded separately from local storage and is not required for acceptance.

External mail transport and cloud connections are capability records. Local receipt remains the acceptance gate.

## Omission

Fixtures without mapped provider forms omit this evidence. Mapped forms without accepted evidence fail closed.
