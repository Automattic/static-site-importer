import assert from 'node:assert/strict';
import { mkdtempSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import test from 'node:test';

import { collectFixtureMatrixRunResults } from '../lib/fixture-matrix/collectors/run-intake.mjs';
import { providerSubmissionReportProjection, sha256, validateProviderSubmissionEvidence } from '../lib/fixture-matrix/provider-submission-evidence.mjs';
import { normalizeFixtureMatrixResult } from '../lib/fixture-matrix/result.mjs';

const FORM = 'a'.repeat(64);
const PLAN = 'b'.repeat(64);

function contract() {
  const materializationReceipt = { schema: 'static-site-importer/materialization-receipt/v2', status: 'completed', plan_hash: PLAN };
  const requirements = [{ required: true, page_route: '/contact', form_identity: FORM, provider_id: 'wordpress/forms', provider_owner: 'wordpress' }];
  const evidence = [{
    schema: 'static-site-importer/provider-submission-evidence/v1', fixture_id: 'nimbus',
    page: { route: '/contact', wordpress_entity_id: '42' }, form_identity: FORM,
    provider: { id: 'wordpress/forms', version: '1.4.2', ownership: 'wordpress', submission_endpoint: { scope: 'wordpress-local', source_endpoint_contacted: false } },
    network: { external_request_origins: [] }, plan_hash: PLAN, materialization_receipt_sha256: sha256(materializationReceipt),
    behaviors: {
      required_field_failure: { status: 'passed', ui: 'validation_error', local_receipt_count: 0 },
      valid_submission: { status: 'passed', ui: 'success', local_receipt: { id: 'submission-42-1', sha256: 'c'.repeat(64), storage: 'wordpress-local' } },
      provider_failure: { status: 'passed', ui: 'provider_error', local_receipt_count: 0 },
      duplicate_submit: { status: 'passed', ui: 'success', local_receipt_count: 1, receipt_sha256: 'c'.repeat(64) },
    },
    notification: { capability: 'separate', attempted: false }, artifact_ref: { path: 'nimbus/provider-submission.json' },
  }];
  return { fixtureId: 'nimbus', requirements, evidence, materializationReceipt, routeEntityMapping: [{ route: '/contact', entity: '42' }] };
}

test('accepts WordPress-local submission and projects a concise import report result', () => {
  const input = contract();
  assert.deepEqual(validateProviderSubmissionEvidence(input), { required: true, valid: true, errors: [] });
  assert.deepEqual(providerSubmissionReportProjection(input), { schema: 'static-site-importer/provider-submission-evidence/v1', status: 'verified', required_form_count: 1, verified_form_count: 1, errors: [] });
});

test('does not gate fixtures with no required provider submission', () => {
  assert.deepEqual(validateProviderSubmissionEvidence({ requirements: [], evidence: [] }), { required: false, valid: true, errors: [] });
});

test('matrix retains runtime evidence and projects verification into the import report', () => {
  const input = contract();
  input.requirements[0].page_route = 'index.html';
  input.evidence[0].page.route = 'index.html';
  const result = normalizeFixtureMatrixResult({
    matrix: { id: 'matrix', fixtures: [{ id: 'nimbus', fixture_corpus: 'solved', fixture_class: 'marketing/static', provider_submissions: input.requirements }] },
    results: [{ fixture_id: 'nimbus', status: 'passed', surface_records: [{ surface_id: 'front-page', post_id: '42' }], matrix_evidence: { materialization_receipt: input.materializationReceipt }, provider_submission_evidence: input.evidence, import_report: { schema: 'static-site-importer/import-report/v1' } }],
  });
  assert.deepEqual(result.fixtures[0].provider_submission_evidence, input.evidence);
  assert.equal(result.fixtures[0].import_report.provider_submission_verification.status, 'verified');
});

test('matrix intake collects the typed WordPress runtime payload', () => {
  const input = contract();
  const outputDirectory = mkdtempSync(path.join(tmpdir(), 'ssi-provider-submission-'));
  try {
    const result = collectFixtureMatrixRunResults({
      matrix: { id: 'matrix', fixtures: [{ id: 'nimbus', fixture_path: '/fixtures/nimbus', fixture_corpus: 'solved', fixture_class: 'marketing/static' }] },
      outputDirectory,
      codeboxOutput: { executions: input.evidence },
    });
    assert.deepEqual(result.fixtures[0].provider_submission_evidence, input.evidence);
  } finally { rmSync(outputDirectory, { recursive: true, force: true }); }
});

for (const [name, mutate, code] of [
  ['declared provider ownership', (x) => { x.requirements[0].provider_owner = 'source'; }, 'provider_submission_requirement_invalid'],
  ['fixture identity', (x) => { x.evidence[0].fixture_id = 'other'; }, 'provider_submission_identity_mismatch'],
  ['page identity', (x) => { x.evidence[0].page.route = '/other'; }, 'provider_submission_evidence_missing'],
  ['WordPress entity identity', (x) => { x.evidence[0].page.wordpress_entity_id = '99'; }, 'provider_submission_identity_mismatch'],
  ['form identity', (x) => { x.evidence[0].form_identity = 'd'.repeat(64); }, 'provider_submission_evidence_missing'],
  ['provider identity', (x) => { x.evidence[0].provider.id = 'source/forms'; }, 'provider_submission_provider_mismatch'],
  ['provider version', (x) => { x.evidence[0].provider.version = 'latest'; }, 'provider_submission_provider_mismatch'],
  ['endpoint ownership', (x) => { x.evidence[0].provider.ownership = 'source'; }, 'provider_submission_endpoint_not_wordpress_owned'],
  ['source endpoint contact', (x) => { x.evidence[0].provider.submission_endpoint.source_endpoint_contacted = true; }, 'provider_submission_endpoint_not_wordpress_owned'],
  ['Wix external request', (x) => { x.evidence[0].network.external_request_origins = ['https://www.wix.com']; }, 'provider_submission_endpoint_not_wordpress_owned'],
  ['plan identity', (x) => { x.evidence[0].plan_hash = 'd'.repeat(64); }, 'provider_submission_materialization_mismatch'],
  ['materialization receipt', (x) => { x.evidence[0].materialization_receipt_sha256 = 'd'.repeat(64); }, 'provider_submission_materialization_mismatch'],
  ['missing materialization receipt', (x) => { x.materializationReceipt = null; }, 'provider_submission_materialization_mismatch'],
  ['required-field behavior', (x) => { x.evidence[0].behaviors.required_field_failure.local_receipt_count = 1; }, 'provider_submission_required_field_failure_unproven'],
  ['valid success UI', (x) => { x.evidence[0].behaviors.valid_submission.ui = 'error'; }, 'provider_submission_valid_success_unproven'],
  ['local receipt', (x) => { x.evidence[0].behaviors.valid_submission.local_receipt.storage = 'email'; }, 'provider_submission_valid_success_unproven'],
  ['provider failure UI', (x) => { x.evidence[0].behaviors.provider_failure.ui = 'success'; }, 'provider_submission_provider_failure_unproven'],
  ['duplicate submission', (x) => { x.evidence[0].behaviors.duplicate_submit.local_receipt_count = 2; }, 'provider_submission_duplicate_behavior_unproven'],
  ['notification separation', (x) => { x.evidence[0].notification.attempted = true; }, 'provider_submission_notification_not_separate'],
  ['artifact reference', (x) => { x.evidence[0].artifact_ref.path = '../evidence.json'; }, 'provider_submission_artifact_missing'],
]) {
  test(`fails closed for ${name}`, () => {
    const input = contract(); mutate(input);
    assert.equal(validateProviderSubmissionEvidence(input).valid, false);
    assert.ok(validateProviderSubmissionEvidence(input).errors.includes(code));
  });
}
