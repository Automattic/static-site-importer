/**
 * External dependencies
 */
import assert from 'node:assert/strict';
import { execSync, spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { fileURLToPath } from 'node:url';

/**
 * Internal dependencies
 */
import { promoteSolvedFixture } from './promote-solved-fixture.mjs';

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

function createTempGitRepo(prefix) {
  const root = mkdtempSync(path.join(tmpdir(), prefix));
  execSync('git init', { cwd: root, stdio: 'ignore' });
  execSync('git config user.email "test@example.com"', { cwd: root, stdio: 'ignore' });
  execSync('git config user.name "Test"', { cwd: root, stdio: 'ignore' });
  return root;
}

function writeRegistry(root, decisions) {
  const registry = {
    schema: 'static-site-importer/gutenberg-incompatibility-registry/v1',
    matrix_id: 'test-matrix',
    fixture_decisions: decisions,
  };
  const filePath = path.join(root, 'gutenberg-incompatibility-registry.json');
  writeFileSync(filePath, JSON.stringify(registry, null, 2));
  return filePath;
}

function setupFixture(blocksEngine, fixtureId) {
  const fixtureDir = path.join(blocksEngine, 'fixtures', 'websites', fixtureId);
  const solvedDir = path.join(blocksEngine, 'fixtures', 'solved');
  mkdirSync(fixtureDir, { recursive: true });
  mkdirSync(solvedDir, { recursive: true });
  writeFileSync(path.join(fixtureDir, 'index.html'), `<h1>${fixtureId}</h1>`);
  writeFileSync(path.join(fixtureDir, 'fixture.json'), JSON.stringify({ fixture_class: 'marketing/static' }));
  execSync('git add fixtures', { cwd: blocksEngine, stdio: 'ignore' });
  execSync(`git commit -m "add ${fixtureId}"`, { cwd: blocksEngine, stdio: 'ignore' });
}

test('refuses to promote a fixture that is not solved_candidate', () => {
  const blocksEngine = createTempGitRepo('ssi-promote-not-solved-');
  setupFixture(blocksEngine, 'visual-only');
  const registryPath = writeRegistry(mkdtempSync(path.join(tmpdir(), 'ssi-promote-registry-')), [
    { fixture_id: 'visual-only', acceptance_status: 'visual_only_blocker' },
  ]);

  assert.throws(
    () => promoteSolvedFixture({ fixtureId: 'visual-only', registry: registryPath, blocksEngine }),
    /acceptance_status "visual_only_blocker".*requires "solved_candidate"/
  );
  assert.strictEqual(spawnSync('git', ['-C', blocksEngine, 'status', '--porcelain']).stdout.toString(), '');
});

test('refuses to promote a missing registry row', () => {
  const blocksEngine = createTempGitRepo('ssi-promote-missing-row-');
  setupFixture(blocksEngine, 'missing');
  const registryPath = writeRegistry(mkdtempSync(path.join(tmpdir(), 'ssi-promote-registry-')), [
    { fixture_id: 'other', acceptance_status: 'solved_candidate' },
  ]);

  assert.throws(
    () => promoteSolvedFixture({ fixtureId: 'missing', registry: registryPath, blocksEngine }),
    /Fixture "missing" not found in registry decisions/
  );
});

test('promotes a solved_candidate fixture via git mv', () => {
  const blocksEngine = createTempGitRepo('ssi-promote-solved-');
  setupFixture(blocksEngine, 'simple-site');
  const registryPath = writeRegistry(mkdtempSync(path.join(tmpdir(), 'ssi-promote-registry-')), [
    { fixture_id: 'simple-site', acceptance_status: 'solved_candidate' },
  ]);

  const result = promoteSolvedFixture({ fixtureId: 'simple-site', registry: registryPath, blocksEngine });

  assert.strictEqual(result.ok, true);
  assert.strictEqual(result.fixture_id, 'simple-site');
  assert.strictEqual(result.blocks_engine, path.resolve(blocksEngine));
  assert.strictEqual(spawnSync('git', ['-C', blocksEngine, 'status', '--porcelain']).stdout.toString().includes('fixtures/solved/simple-site'), true);
  assert.strictEqual(spawnSync('git', ['-C', blocksEngine, 'ls-files', 'fixtures/websites/simple-site']).stdout.toString(), '');
  assert.strictEqual(spawnSync('git', ['-C', blocksEngine, 'ls-files', 'fixtures/solved/simple-site/index.html']).stdout.toString().trim(), 'fixtures/solved/simple-site/index.html');
});
