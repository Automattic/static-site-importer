import assert from 'node:assert/strict';
import { mkdtemp, writeFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import test from 'node:test';
import { SCHEMA, buildOracleInput, notVerifiedResult } from './visual-parity-oracle.mjs';

const viewport = { width: 1440, height: 900 };

function layoutDoc(pageId, extras = {}) {
  return {
    schema: SCHEMA,
    viewports: [viewport],
    pages: [{
      id: pageId,
      viewport,
      sections: [{
        id: 'hero',
        order: 0,
        offset: { top: 80 },
        height: 900,
        headings: [{ font_size: 48 }],
        media: [{ id: 'header-logo', display_width: 36, display_height: 36 }],
      }],
      landmarks: [{ id: 'banner', role: 'banner', height: 80, media: [{ id: 'header-logo', display_width: 36, display_height: 36 }] }],
    }],
    intentional_omissions: [],
    ...extras,
  };
}

test('degrades to not_verified without a layout baseline or imported render', async () => {
  const result = await buildOracleInput({});
  assert.equal(result.status, 'not_verified');
  assert.equal(result.verification, 'not_verified');
  assert.equal(result.stage, 'import_vs_baseline');
  assert.equal(result.compiler_report_path, 'source_reports.layout_baseline');
  assert.ok(result.missing_data_contract.includes('source_reports.layout_baseline.pages'));
});

test('loads SSI-schema baseline and imported render without a browser', async () => {
  const root = await mkdtemp(join(tmpdir(), 'layout-baseline-'));
  const baselinePath = join(root, 'baseline.json');
  const importedPath = join(root, 'imported.json');
  await writeFile(baselinePath, JSON.stringify(layoutDoc('index')));
  await writeFile(importedPath, JSON.stringify(layoutDoc('index')));
  const result = await buildOracleInput({ baseline: baselinePath, importedRender: importedPath });
  assert.equal(result.status, 'ready');
  assert.equal(result.verification, 'section_geometry');
  assert.equal(result.source_reports.layout_baseline.pages[0].sections[0].height, 900);
  assert.equal(result.imported_render.pages[0].landmarks[0].height, 80);
});

test('malformed baseline yields missing_data_contract rather than ready', async () => {
  const result = await buildOracleInput({ baseline: { schema: 'other', pages: [] } });
  assert.equal(result.status, 'not_verified');
  assert.ok(result.missing_data_contract.includes('source_reports.layout_baseline schema static-site-importer/layout-baseline/v1'));
});

test('notVerifiedResult is explicit and non-failing', () => {
  const result = notVerifiedResult('no browser');
  assert.equal(result.status, 'not_verified');
  assert.deepEqual(result.missing_data_contract, []);
});
