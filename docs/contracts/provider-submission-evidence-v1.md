# Provider Submission Evidence v1

`static-site-importer/provider-submission-evidence/v1` is the fail-closed receipt proving that a mapped provider form accepted a valid submission in the target WordPress runtime.

SSI owns this evidence. Provider adapters supply mapped form identity; the verifier stores a WordPress-owned local receipt and never follows a source endpoint or sends mail.

Notification capability is recorded separately from local receipt storage. A missing cloud connection or mail transport does not prevent a stored receipt.

`accepted` requires required-field failure, valid success, provider failure, and duplicate-submit behavior, a stored local receipt, `endpoint_kind: wordpress_owned`, and `notification.sent: false`. Mapped forms that cannot prove that contract fail closed.
