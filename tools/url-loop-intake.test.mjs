import assert from 'node:assert/strict';
import test from 'node:test';
import { CAPTURE_RECEIPT_SCHEMA, DLA_RELEASE, createHandoff, runUrlLoopIntake, validateArtifactSelection, validateCapture, validateMatrixEvidence } from './url-loop-intake.mjs';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

test('capture validation follows the real DLA receipt contract', () => {
  const result = validateCapture({ schema: CAPTURE_RECEIPT_SCHEMA, source: { url: 'https://example.com/' }, summary: { complete: false, routesCaptured: 1, routesDiscovered: 2, routesFailed: 1, routesSkipped: 0 } });
  assert.equal(result.valid, false);
  assert.deepEqual(result.failures, ['capture_incomplete']);
});

test('complete DLA receipt does not require invented top-level fields', () => {
  const result = validateCapture({ schema: CAPTURE_RECEIPT_SCHEMA, source: { url: 'https://example.com/' }, summary: { complete: true, routesCaptured: 8, routesDiscovered: 8, routesFailed: 0, routesSkipped: 0 } });
  assert.equal(result.valid, true);
});

test('the configured DLA release identity is immutable and uses the published CLI shape', () => {
  assert.equal(DLA_RELEASE.version, 'v0.6.5');
  assert.equal(DLA_RELEASE.commit, '880575b81cd837322520d05d260f5d682506afbb');
  assert.match(DLA_RELEASE.asset, /data-liberation-0\.6\.5\.tgz$/);
});

test('artifact selection rejects ambiguous generated artifact directories', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-url-loop-'));
  for (const name of ['one', 'two']) {
    fs.mkdirSync(path.join(root, name), { recursive: true });
    fs.writeFileSync(path.join(root, name, 'index.html'), '<!doctype html>');
  }
  assert.throws(() => validateArtifactSelection(root), /ambiguous/);
});

test('real receipt fixture selects its website directory for SSI materialization', () => {
  const website = path.resolve('tests/fixtures/url-loop/complete-capture/website');
  const selected = validateArtifactSelection(website);
  assert.equal(selected.source, website);
  assert.equal(selected.kind, 'static_directory');
});

test('intake records derived provenance and materializes the retained website', async () => {
  const outputRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-url-loop-run-'));
  const result = await runUrlLoopIntake({ url: 'https://example.com', outputRoot }, {
    spawn(command, args) {
      assert.equal(command, 'npx');
      assert.deepEqual(args.slice(0, 3), ['--yes', `--package=${DLA_RELEASE.asset}`, 'data-liberation']);
      const captureRoot = args[args.indexOf('--output') + 1];
      const sourceRoot = path.join(captureRoot, 'example.com');
      fs.mkdirSync(path.join(sourceRoot, 'website'), { recursive: true });
      fs.writeFileSync(path.join(sourceRoot, 'capture-receipt.json'), JSON.stringify({ schema: CAPTURE_RECEIPT_SCHEMA, source: { url: 'https://example.com/' }, summary: { complete: true, routesCaptured: 8, routesDiscovered: 8, routesFailed: 0, routesSkipped: 0 } }));
      fs.writeFileSync(path.join(sourceRoot, 'website', 'index.html'), '<!doctype html><main>fixture</main>');
      for (let index = 1; index < 8; index += 1) fs.writeFileSync(path.join(sourceRoot, 'website', `route-${index}.html`), `<main>route ${index}</main>`);
      return { status: 0 };
    },
  });
  assert.equal(result.status, 'needs_evaluation');
  assert.match(result.provenance.source_url_sha256, /^sha256:[a-f0-9]{64}$/);
  assert.equal(fs.readFileSync(path.join(result.fixture.directory, 'index.html'), 'utf8'), '<!doctype html><main>fixture</main>');
});

test('dry run does not invoke DLA or SSI materialization and remains blocked', async () => {
  let invoked = false;
  const outputRoot = path.join(os.tmpdir(), `ssi-url-loop-dry-${Date.now()}`);
  const result = await runUrlLoopIntake({ url: 'https://example.com', outputRoot, dryRun: true }, {
    spawn() { invoked = true; return { status: 0 }; },
  });
  assert.equal(invoked, false);
  assert.equal(result.status, 'blocked');
  assert.equal(result.acceptance.solved, false);
  assert.equal(result.handoff_path, null);
  assert.equal(fs.existsSync(outputRoot), false);
});

test('handoff never infers solved status from fallback-free output', () => {
  const handoff = createHandoff({ url: 'https://example.com/', provenance: { source_url_sha256: 'sha256:' + 'a'.repeat(64) }, matrix: { status: 'passed', fallback_count: 0 } });
  assert.equal(handoff.acceptance.solved, false);
  assert.equal(handoff.status, 'blocked');
});

test('complete capture without a matrix is actionable for evaluation', () => {
  const handoff = createHandoff({
    url: 'https://example.com/',
    captureReceipt: { schema: CAPTURE_RECEIPT_SCHEMA },
    fixture: { id: 'example' },
  });
  assert.equal(handoff.status, 'needs_evaluation');
  assert.equal(handoff.acceptance.solved, false);
});

test('verified canonical matrix evidence is reviewable without a finding packet', () => {
  const handoff = createHandoff({
    url: 'https://example.com/',
    fixture: { id: 'example' },
    matrix: {
      status: 'passed',
      evidence_complete: true,
      evidence: {
        schema: 'static-site-importer/fixture-matrix-runtime-evidence-summary/v1',
        status: 'verified',
      },
      artifact_refs: ['homeboy-runs:matrix-1'],
    },
  });
  assert.equal(handoff.status, 'needs_review');
  assert.deepEqual(handoff.finding_packet_refs, []);
  assert.equal(handoff.acceptance.solved, false);
});

test('matrix runtime blockers retain typed stage and actual outcome', () => {
  const failure = { stage: 'matrix', reason: 'command_timeout', outcome: 'timed_out', exit_status: null };
  const handoff = createHandoff({ url: 'https://example.com/', matrix: { status: 'failed' }, failures: [failure] });
  assert.equal(handoff.status, 'blocked');
  assert.deepEqual(handoff.failures, [failure]);
});

test('matrix evidence fails closed for missing, pending, or failed runtime rows', () => {
  assert.deepEqual(validateMatrixEvidence({}, 'example'), { valid: false, reason: 'authoritative_output_missing' });
  const base = {
    matrix_evidence_readiness: {
      schema: 'static-site-importer/fixture-matrix-runtime-evidence-summary/v1',
      fixtures: [{ fixture_id: 'example', readiness: 'pending' }],
    },
  };
  assert.deepEqual(validateMatrixEvidence(base, 'example'), { valid: false, reason: 'runtime_evidence_incomplete' });
  assert.deepEqual(validateMatrixEvidence({ ...base, matrix_evidence_readiness: { ...base.matrix_evidence_readiness, fixtures: [{ fixture_id: 'example', readiness: 'failed' }] } }, 'example'), { valid: false, reason: 'runtime_evidence_incomplete' });
});

test('matrix evidence requires verified evidence for the selected fixture', () => {
  const summary = {
    matrix_evidence_readiness: {
      schema: 'static-site-importer/fixture-matrix-runtime-evidence-summary/v1',
      fixtures: [{ fixture_id: 'other', readiness: 'verified' }, { fixture_id: 'example', readiness: 'verified' }],
    },
  };
  assert.deepEqual(validateMatrixEvidence(summary, 'example'), { valid: true, reason: null });
  assert.deepEqual(validateMatrixEvidence(summary, 'missing'), { valid: false, reason: 'runtime_evidence_incomplete' });
});
