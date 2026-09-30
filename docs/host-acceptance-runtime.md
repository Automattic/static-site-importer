# Published host acceptance API

The existing checksum-verified SSI release ZIP carries the host runtime at
`static-site-importer/assets/host-acceptance/`. Both the HTML and full website
profiles include this directory. A separate release archive or dependency
installation is unnecessary: import and acceptance use the same SSI release.

`manifest.json` declares the API schema, SSI version, public `index.mjs`, entry
SHA-256, Node floor and caller-owned Playwright dependency. The caller verifies
the outer ZIP using its normal release asset digest, extracts it into its runtime
cache, verifies the entry digest/version, and supplies its Playwright package in
the extracted package's Node module resolution path (the same contract used by
Studio's DLA runtime loader).

```js
import {
  HOST_ACCEPTANCE_API,
  ACCEPTANCE_SCHEMA,
  runExistingRuntimeAcceptance,
  consumeExistingRuntimeAcceptance,
} from './static-site-importer/assets/host-acceptance/index.mjs';

const report = await runExistingRuntimeAcceptance(callerConfig);
```

The API and evidence contract remain those in `existing-runtime-acceptance.md`.
Pure PNG/pixel-comparison dependencies are bundled; Playwright and the validated
DLA public runtime remain caller-owned. The host entry is an ESM API, not a
WordPress bootstrap or a second comparator. Importing it needs no source checkout,
development trees or bundled browser executable.

## Build and proof

`npm run build` creates the ignored host assets from the existing implementation.
Homeboy's WordPress build already runs this step before production dependency
pruning. The package profiles require the entry and manifest, so an absent host
build cannot silently produce a complete release ZIP. The existing paired
development-package tool uses the same build/profile path.

`node --test tools/host-acceptance-package.test.mjs` builds into an unrelated
directory, verifies the digest, borrows only caller Playwright, imports the public
API and runs real browser health checks. Missing fidelity/editor evidence stays
pending; a fatal WordPress page fails. Runtime-profile tests prove both ZIP
profiles include host assets while excluding repository development trees.
