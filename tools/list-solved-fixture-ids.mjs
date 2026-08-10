#!/usr/bin/env node

import { discoverFixtures } from '../lib/fixture-matrix/fixtures.mjs';

const fixtureRoot = process.argv[2];
if (!fixtureRoot) {
  process.stderr.write('Usage: node tools/list-solved-fixture-ids.mjs <fixture-root>\n');
  process.exitCode = 1;
} else {
  const ids = discoverFixtures(fixtureRoot, { maxDepth: 2 })
    .filter((fixture) => fixture.fixture_corpus === 'solved')
    .map((fixture) => fixture.id)
    .sort();
  if (!ids.length) {
    process.stderr.write(`No solved fixtures found under ${fixtureRoot}.\n`);
    process.exitCode = 1;
  } else {
    process.stdout.write(`${ids.join(',')}\n`);
  }
}
