import assert from 'node:assert/strict';
import { mkdir, mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { buildOracleInput, notVerifiedResult } from './visual-parity-oracle.mjs';

test('degrades to not_verified without section records or a browser', async () => {
  const result = await buildOracleInput({});
  assert.equal(result.status, 'not_verified');
  assert.equal(result.verification, 'not_verified');
  assert.equal(result.stage, 'import_vs_capture');
});

test('loads capture and imported section directories without a browser', async () => {
  const root = await mkdtemp(join(tmpdir(), 'visual-parity-oracle-'));
  const source = join(root, 'source');
  const imported = join(root, 'imported');
  await mkdir(source);
  await mkdir(imported);
  await writeFile(join(source, 'homepage.json'), JSON.stringify({
    sourceUrl: 'https://example.test/',
    viewport: { width: 1440, height: 900 },
    sections: [{ sectionIndex: 0, top: 80, height: 900, headingSizes: [48], images: [] }],
    landmarks: [{ role: 'header', mediaCount: 1 }],
  }));
  await writeFile(join(imported, 'homepage.json'), JSON.stringify({
    sourceUrl: 'http://localhost/',
    viewport: { width: 1440, height: 900 },
    sections: [{ sectionIndex: 0, top: 80, height: 900, headingSizes: [48], images: [] }],
    landmarks: [{ role: 'header', mediaCount: 1, height: 80 }],
  }));
  const result = await buildOracleInput({ sourceSections: source, importedSections: imported });
  assert.equal(result.status, 'ready');
  assert.equal(result.verification, 'section_geometry');
  assert.equal(result.source_pages.homepage.sections[0].height, 900);
  assert.equal(result.imported_pages.homepage.landmarks[0].height, 80);
});

test('notVerifiedResult is explicit and non-failing', () => {
  const result = notVerifiedResult('no browser');
  assert.equal(result.status, 'not_verified');
  assert.deepEqual(result.source_pages, {});
});
