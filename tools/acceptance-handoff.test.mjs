import assert from 'node:assert/strict';
import { mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
  consumeAcceptanceHandoff,
  produceAcceptanceHandoff,
  validateAcceptanceHandoff,
} from '../lib/fixture-matrix/acceptance-handoff.mjs';

function completeInput(directory, overrides = {}) {
  const source = path.join(directory, 'source.txt');
  const candidate = path.join(directory, 'candidate.txt');
  const diff = path.join(directory, 'diff.txt');
  writeFileSync(source, 'roeeby responsive source\n');
  writeFileSync(candidate, 'roeeby responsive candidate\n');
  writeFileSync(diff, 'roeeby responsive diff\n');
  return {
    outputDirectory: path.join(directory, 'handoff'),
    fixtureId: 'roeeby-responsive-canonical',
    inputIdentity: { schema: 'static-site-importer/input-identity/v1', sha256: 'a'.repeat(64) },
    compilerIdentity: { schema: 'static-site-importer/compiler-identity/v1', name: 'generic-compiler', version: '1' },
    sitePlan: { schema: 'blocks-engine/wordpress-site-plan/v2', pages: [{ route: '/' }], routes: [{ path: '/' }] },
    materializationReceipt: { schema: 'static-site-importer/materialization-receipt/v2', status: 'completed', completed: { materialized_pages: { '/': { post_id: 7 } } } },
    routeEntityMapping: [{ route: '/', entity: '7' }],
    visualEvidence: [{ source_file: source, candidate_file: candidate, diff_file: diff, route: '/', viewport: { width: 1440, height: 900 }, entity: '7' }],
    editorEvidence: [{ route: '/', entity: '7', validation: { schema: 'wp-codebox/editor-validate-blocks/v1', invalid_blocks: 0 } }],
    qualityProjection: { schema: 'static-site-importer/quality-budget-admission/v1', production_status: 'passed' },
    ...overrides,
  };
}

test('acceptance handoff accepts a complete hash-bound generic fixture', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ssi-handoff-'));
  try {
    const document = produceAcceptanceHandoff(completeInput(directory));
    assert.equal(document.disposition, 'passed');
    assert.deepEqual(consumeAcceptanceHandoff(path.join(directory, 'handoff'), document), { schema: 'static-site-importer/acceptance-handoff/v1', disposition: 'passed', reasons: [] });
  } finally { rmSync(directory, { recursive: true, force: true }); }
});

test('acceptance handoff records absent evidence without inferring it', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ssi-handoff-'));
  try {
    const document = produceAcceptanceHandoff(completeInput(directory, { sitePlan: null, visualEvidence: [], editorEvidence: [] }));
    assert.equal(document.disposition, 'not_proven');
    assert.deepEqual(document.not_proven.map((reason) => reason.code), ['site_plan_missing', 'visual_evidence_missing', 'editor_evidence_missing']);
  } finally { rmSync(directory, { recursive: true, force: true }); }
});

test('acceptance handoff rejects hash drift', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ssi-handoff-'));
  try {
    const document = produceAcceptanceHandoff(completeInput(directory));
    const handoff = path.join(directory, 'handoff');
    writeFileSync(path.join(handoff, document.input_identity.path), 'changed');
    const validation = validateAcceptanceHandoff(handoff, document);
    assert.equal(validation.valid, false);
    assert.deepEqual(validation.reasons, [{ code: 'hash_mismatch' }]);
  } finally { rmSync(directory, { recursive: true, force: true }); }
});

test('acceptance handoff preserves failed materialization as failed', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ssi-handoff-'));
  try {
    const document = produceAcceptanceHandoff(completeInput(directory, { materializationReceipt: { schema: 'static-site-importer/materialization-receipt/v2', status: 'failed' } }));
    assert.equal(document.disposition, 'failed');
    assert.deepEqual(consumeAcceptanceHandoff(path.join(directory, 'handoff'), document).reasons, [{ code: 'materialization_failed' }]);
  } finally { rmSync(directory, { recursive: true, force: true }); }
});
