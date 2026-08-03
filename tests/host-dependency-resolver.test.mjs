import assert from 'node:assert/strict';
import { mkdtemp, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createHash } from 'node:crypto';
import { resolveHostDependencyPlan } from '../bench/static-site-fixture-matrix.bench.mjs';

const entry = (slug, file) => ({ source_kind: 'wordpress.org-plugin', slug, package: slug, plugin_entrypoint: file, activation: 'required' });
const plan = { schema: 'static-site-importer/runtime-dependency-plan/v1', artifact_sha256: 'a'.repeat(64), entries: [entry('jetpack', 'jetpack/jetpack.php'), entry('woocommerce', 'woocommerce/woocommerce.php')] };
const archive = Buffer.from('PK\x03\x04fixture');
const fetcher = async (url) => String(url).includes('api.wordpress.org')
  ? new Response(JSON.stringify({ version: '1.2.3', download_link: `https://downloads.wordpress.org/plugin/${String(url).includes('jetpack') ? 'jetpack' : 'woocommerce'}.1.2.3.zip` }), { headers: { 'content-type': 'application/json' } })
  : new Response(archive, { headers: { 'content-type': 'application/zip', 'content-length': String(archive.length) } });
const root = await mkdtemp(join(tmpdir(), 'ssi-host-deps-'));
try {
  const resolved = await resolveHostDependencyPlan(plan, root, fetcher);
  assert.equal(resolved.entries.length, 2);
  for (const item of resolved.entries) {
    assert.match(item.host_resolution.archive_path, /package\.zip$/);
    assert.equal(item.host_resolution.archive_sha256, createHash('sha256').update(archive).digest('hex'));
    assert.match(item.host_resolution.source_url, /^https:\/\/downloads\.wordpress\.org\//);
  }
  assert.deepEqual((await resolveHostDependencyPlan({ ...plan, entries: [] }, root, () => { throw new Error('no fetch'); })).entries, []);
  await assert.rejects(() => resolveHostDependencyPlan({ ...plan, entries: [entry('jetpack', 'jetpack/jetpack.php')] }, root, async () => new Response(JSON.stringify({ version: '1.2.3', download_link: 'http://evil.test/plugin.zip' }), { headers: { 'content-type': 'application/json' } })), /invalid immutable package/);
} finally { await rm(root, { recursive: true, force: true }); }
console.log('host dependency resolver ok');
