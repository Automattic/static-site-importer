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
import { sha256 } from '../lib/fixture-matrix/provider-submission-evidence.mjs';

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

test('acceptance handoff copies and validates required provider submission evidence', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ssi-handoff-'));
  try {
    const input = completeInput(directory);
    input.materializationReceipt.plan_hash = 'b'.repeat(64);
    input.providerSubmissionRequirements = [{ required: true, page_route: '/', form_identity: 'a'.repeat(64), provider_id: 'wordpress/forms', provider_owner: 'wordpress' }];
    input.providerSubmissionEvidence = [{ schema: 'static-site-importer/provider-submission-evidence/v1', fixture_id: input.fixtureId, page: { route: '/', wordpress_entity_id: '7' }, form_identity: 'a'.repeat(64), provider: { id: 'wordpress/forms', version: '1.0.0', ownership: 'wordpress', submission_endpoint: { scope: 'wordpress-local', source_endpoint_contacted: false } }, network: { external_request_origins: [] }, plan_hash: 'b'.repeat(64), materialization_receipt_sha256: sha256(input.materializationReceipt), behaviors: { required_field_failure: { status: 'passed', ui: 'validation_error', local_receipt_count: 0 }, valid_submission: { status: 'passed', ui: 'success', local_receipt: { id: 'local-1', sha256: 'c'.repeat(64), storage: 'wordpress-local' } }, provider_failure: { status: 'passed', ui: 'provider_error', local_receipt_count: 0 }, duplicate_submit: { status: 'passed', ui: 'success', local_receipt_count: 1, receipt_sha256: 'c'.repeat(64) } }, notification: { capability: 'separate', attempted: false }, artifact_ref: { path: 'runtime/provider.json' } }];
    const document = produceAcceptanceHandoff(input);
    assert.equal(document.disposition, 'passed');
    assert.equal(validateAcceptanceHandoff(path.join(directory, 'handoff'), document).valid, true);
  } finally { rmSync(directory, { recursive: true, force: true }); }
});
