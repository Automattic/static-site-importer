import assert from 'node:assert/strict';
import { mkdtempSync, readFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
  OWNER_HANDOFF_EVIDENCE_SCHEMA,
  OWNER_TASK_IDS,
  consumeOwnerHandoffEvidence,
  emitFixtureMatrixOwnerHandoffs,
  produceOwnerHandoffEvidence,
  validateOwnerHandoffEvidence,
} from '../lib/fixture-matrix/owner-handoff-evidence.mjs';

const HASH = 'a'.repeat(64);
const PLAN = { schema: 'blocks-engine/wordpress-site-plan-identity/v1', hash: HASH };
const RECEIPT = {
  schema: 'static-site-importer/materialization-receipt/v2',
  status: 'completed',
  plan_identity: PLAN,
  rollback: { status: 'not_requested' },
};

function completeInput(overrides = {}) {
  return {
    planIdentity: PLAN,
    materializationReceipt: RECEIPT,
    dimensions: {
      visual_acceptance: { desktop: { status: 'passed' }, mobile: { status: 'passed' } },
      editability_shared_regions: { schema: 'static-site-importer/editability-report-admission/v1', status: 'passed' },
      editor_presentation_persistence: {
        schema: 'static-site-importer/editor-presentation-evidence/v2',
        coverage_complete: true,
        persistence: { persisted: true, reloaded: true },
      },
      media_library_ownership: { attachment_count: 3, replaceable_media_count: 3 },
      link_portability: { unresolved_internal_count: 0, external_inventory: ['https://example.com'] },
      provider_functionality: { receipts: [{ status: 'passed' }] },
      site_identity_metadata: { title: 'Nimbus Commute', placeholders: [] },
      accessibility: { schema: 'static-site-importer/accessibility-evidence/v1', status: 'passed' },
      frontend_performance: { schema: 'static-site-importer/frontend-performance-evidence/v1', status: 'passed' },
      deployment_rollback: { status: 'not_requested' },
      owner_tasks: Object.fromEntries(OWNER_TASK_IDS.map((id) => [id, { persisted: true, reloaded: true }])),
    },
    ...overrides,
  };
}

test('owner handoff accepts complete hash-bound evidence', () => {
  const document = produceOwnerHandoffEvidence(completeInput());
  assert.equal(document.schema, OWNER_HANDOFF_EVIDENCE_SCHEMA);
  assert.equal(document.disposition, 'passed');
  assert.equal(document.accepted_built_allowed, true);
  assert.equal(document.plan_identity.hash, HASH);
  assert.match(document.materialization_receipt_sha256, /^[a-f0-9]{64}$/);
  assert.deepEqual(consumeOwnerHandoffEvidence(document), {
    schema: OWNER_HANDOFF_EVIDENCE_SCHEMA,
    disposition: 'passed',
    accepted_built_allowed: true,
    reasons: [],
  });
  assert.match(document.report_card, /Accepted\/built allowed: yes/);
});

test('owner handoff records absent evidence without inferring a pass', () => {
  const document = produceOwnerHandoffEvidence({ planIdentity: PLAN, materializationReceipt: RECEIPT });
  assert.equal(document.disposition, 'not_proven');
  assert.equal(document.accepted_built_allowed, false);
  const gaps = document.dimensions.filter((row) => row.status === 'evidence_gap').map((row) => row.id);
  assert.deepEqual(gaps, [
    'visual_acceptance',
    'editability_shared_regions',
    'editor_presentation_persistence',
    'media_library_ownership',
    'link_portability',
    'provider_functionality',
    'site_identity_metadata',
    'accessibility',
    'frontend_performance',
    'owner_tasks',
  ]);
  for (const finding of document.findings) {
    assert.equal(typeof finding.route, 'string');
    assert.equal(typeof finding.component, 'string');
    assert.ok(finding.owning_repository);
    assert.ok(finding.recommended_next_action);
  }
});

test('owner-task text persistence does not fabricate remaining owner tasks', () => {
  const document = produceOwnerHandoffEvidence(completeInput({
    dimensions: {
      ...completeInput().dimensions,
      owner_tasks: { text_edit: { persisted: true, reloaded: true } },
    },
  }));
  assert.equal(document.disposition, 'not_proven');
  assert.deepEqual(document.owner_tasks.tasks.map((task) => task.id), [...OWNER_TASK_IDS]);
  assert.equal(document.owner_tasks.tasks.find((task) => task.id === 'text_edit').status, 'pass');
  assert.deepEqual(document.owner_tasks.tasks.filter((task) => task.id !== 'text_edit').map((task) => task.status), [
    'evidence_gap',
    'evidence_gap',
    'evidence_gap',
    'evidence_gap',
  ]);
});

test('owner decisions remain reviewable without blocking accepted/built handoff', () => {
  const document = produceOwnerHandoffEvidence(completeInput({
    dimensions: {
      ...completeInput().dimensions,
      site_identity_metadata: { title: 'Nimbus Commute', placeholders: ['{{PHONE}}'] },
    },
  }));
  assert.equal(document.disposition, 'owner_decisions_required');
  assert.equal(document.accepted_built_allowed, true);
  assert.equal(consumeOwnerHandoffEvidence(document).accepted_built_allowed, true);
});

test('hard failures block accepted/built handoff', () => {
  const document = produceOwnerHandoffEvidence(completeInput({
    materializationReceipt: { ...RECEIPT, status: 'failed' },
  }));
  assert.equal(document.disposition, 'failed');
  assert.equal(document.accepted_built_allowed, false);
  assert.equal(consumeOwnerHandoffEvidence(document).accepted_built_allowed, false);
});

test('desktop-only visual evidence stays an evidence gap', () => {
  const document = produceOwnerHandoffEvidence(completeInput({
    dimensions: { ...completeInput().dimensions, visual_acceptance: { desktop: { status: 'passed' } } },
  }));
  assert.equal(document.dimensions.find((row) => row.id === 'visual_acceptance').status, 'evidence_gap');
  assert.equal(document.accepted_built_allowed, false);
});

test('unbound hashes fail closed', () => {
  const document = produceOwnerHandoffEvidence({ dimensions: completeInput().dimensions });
  assert.equal(document.disposition, 'not_proven');
  assert.equal(document.accepted_built_allowed, false);
  assert.equal(validateOwnerHandoffEvidence(document).valid, false);
});

test('owner handoff writes the report card beside hash-bound evidence', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ssi-owner-handoff-'));
  try {
    const document = produceOwnerHandoffEvidence(completeInput({ outputDirectory: directory }));
    assert.equal(JSON.parse(readFileSync(path.join(directory, 'owner-handoff-evidence.json'), 'utf8')).disposition, 'passed');
    assert.match(readFileSync(path.join(directory, 'owner-handoff-report-card.md'), 'utf8'), /Owner handoff report card/);
    assert.equal(document.accepted_built_allowed, true);
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});

test('fixture matrix emission consumes existing receipts and leaves typed gaps', () => {
  const directory = mkdtempSync(path.join(tmpdir(), 'ssi-owner-handoff-matrix-'));
  try {
    const emitted = emitFixtureMatrixOwnerHandoffs({
      outputDirectory: directory,
      result: {
        fixtures: [{
          fixture_id: 'nimbus-commute',
          matrix_evidence: {
            materialization_receipt: { ...RECEIPT, plan_hash: HASH },
          },
        }],
      },
    });
    assert.equal(emitted.handoffs[0].accepted_built_allowed, false);
    assert.equal(emitted.handoffs[0].disposition, 'not_proven');
    const document = JSON.parse(readFileSync(path.join(directory, emitted.handoffs[0].path), 'utf8'));
    assert.equal(document.schema, OWNER_HANDOFF_EVIDENCE_SCHEMA);
    assert.ok(document.findings.every((finding) => finding.status !== 'pass'));
  } finally {
    rmSync(directory, { recursive: true, force: true });
  }
});
