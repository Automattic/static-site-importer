import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import path from 'node:path';

const root = path.resolve(import.meta.dirname, '..');
const fixtures = process.env.PLAYGROUND_FIXTURE_REPO;
const revision = '12c93f35529c563257d144b511b0dbd30eb89ebe';
assert.ok(fixtures, 'Set PLAYGROUND_FIXTURE_REPO to the Blocks Engine fixture checkout');
const directory = await mkdtemp(path.join(tmpdir(), 'ssi-playground-inputs-'));
const archive = (name, fixture, extra = []) => {
  const output = path.join(directory, `${name}.zip`);
  execFileSync('git', ['-C', fixtures, 'archive', '--format=zip', `--output=${output}`, ...extra, `${revision}:fixtures/websites/${fixture}`], { stdio: 'inherit' });
  return output;
};
try {
  const northstar = archive('northstar', '17-markdown-blog-launch-site', ['--prefix=export/']);
  const brightwell = archive('brightwell', '81-dental-practice');
  const invalid = path.join(directory, 'missing-index.zip');
  execFileSync('git', ['-C', fixtures, 'archive', '--format=zip', `--output=${invalid}`, `${revision}:fixtures/websites/17-markdown-blog-launch-site`, 'fixture.json'], { stdio: 'inherit' });
  const base = { ...process.env };
  for (const key of ['PLAYGROUND_SOURCE_URL', 'PLAYGROUND_SOURCE_ZIP', 'PLAYGROUND_INVALID_SOURCE_ZIP', 'PLAYGROUND_EXPECT_THEME', 'PLAYGROUND_MIN_PAGES', 'PLAYGROUND_EXPECT_LOCAL_IMAGE', 'PLAYGROUND_REPEAT_PASTE']) delete base[key];
  const cases = [
    ['paste', {}],
    ['url', { PLAYGROUND_SOURCE_URL: 'https://example.com/', PLAYGROUND_EXPECT_THEME: 'example-domain', PLAYGROUND_MIN_PAGES: '1' }],
    ['nested-multipage-zip', { PLAYGROUND_SOURCE_ZIP: northstar, PLAYGROUND_INVALID_SOURCE_ZIP: invalid, PLAYGROUND_EXPECT_THEME: 'northstar-pantry', PLAYGROUND_MIN_PAGES: '6', PLAYGROUND_REPEAT_PASTE: '1' }],
    ['local-asset-zip', { PLAYGROUND_SOURCE_ZIP: brightwell, PLAYGROUND_EXPECT_THEME: 'brightwell-dental-studio', PLAYGROUND_MIN_PAGES: '1', PLAYGROUND_EXPECT_LOCAL_IMAGE: 'map' }],
  ];
  for (const [name, env] of cases) {
    console.log(`Playground input acceptance: ${name} (fixtures ${revision})`);
    execFileSync(process.execPath, [path.join(root, 'tools/verify-playground-launch.mjs')], { cwd: root, env: { ...base, ...env }, stdio: 'inherit', timeout: 420_000 });
  }
} finally {
  await rm(directory, { recursive: true, force: true });
}
