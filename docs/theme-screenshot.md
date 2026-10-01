# Generated theme screenshot

A website artifact can carry a PNG named `site-preview.png` adjacent to its
entrypoint. Data Liberation produces this preview by rendering its portable
homepage at 1200 × 900, independently of optional full-page capture evidence.

SSI discovers the preview during artifact compilation and materializes its
bytes as `screenshot.png` at the generated theme root. Block and classic themes
use the same transactional preflight, conflict protection, file receipt, and
rollback path. Existing-theme destinations retain their own thumbnail.

Missing or invalid optional previews leave legacy imports supported. SSI accepts
PNG previews up to 10 MiB and 4096 × 4096; the producer supplies the recommended
1200 × 900 dimensions. Theme export carries the thumbnail back into the portable
artifact as `site-preview.png`, preserving it through reimport.

The preview depicts the portable artifact homepage. It is not evidence of the
final WordPress render or of visual parity.

Verification:

```sh
npm run test:site-plan-materializer
php tests/smoke-export-theme-ability.php
npm run test:runtime-package
```
