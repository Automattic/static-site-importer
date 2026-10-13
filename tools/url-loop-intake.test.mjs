import assert from 'node:assert/strict';
import test from 'node:test';
import { CAPTURE_COMMAND_ENV, CAPTURE_RECEIPT_SCHEMA, CAPTURE_RECEIPT_SCHEMA_ENV, createHandoff, resolveCaptureConfig, runUrlLoopIntake, runUrlLoopMatrix, validateArtifactSelection, validateCapture, validateMatrixEvidence } from './url-loop-intake.mjs';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';

// A stub capture command: no producer is named or downloaded by these tests.
const stubCapture = { command: ['stub-capture', '--source', '{url}', '--into', '{output}'] };
const outputOf = (args) => args[args.indexOf('--into') + 1];
// A Node directory holding the stub executable, so a bare argv[0] resolves on the bounded PATH.
function stubToolchain() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-url-loop-stub-'));
  fs.symlinkSync(process.execPath, path.join(root, 'node'));
  fs.writeFileSync(path.join(root, 'stub-capture'), '#!/bin/sh\nexit 0\n', { mode: 0o755 });
  return path.join(root, 'node');
}

test('capture validation follows the capture receipt contract', () => {
  const result = validateCapture({ schema: CAPTURE_RECEIPT_SCHEMA, source: { url: 'https://example.com/' }, summary: { complete: false, routesCaptured: 1, routesDiscovered: 2, routesFailed: 1, routesSkipped: 0 } });
  assert.equal(result.valid, false);
  assert.deepEqual(result.failures, ['capture_incomplete']);
});

test('a complete receipt does not require invented top-level fields', () => {
  const result = validateCapture({ schema: CAPTURE_RECEIPT_SCHEMA, source: { url: 'https://example.com/' }, summary: { complete: true, routesCaptured: 8, routesDiscovered: 8, routesFailed: 0, routesSkipped: 0 } });
  assert.equal(result.valid, true);
});

test('the capture command and receipt schema are caller configuration with a contract default', () => {
  assert.throws(() => resolveCaptureConfig({}, {}), /capture_command_missing/);
  assert.throws(() => resolveCaptureConfig({ captureCommand: 'stub-capture {url} {output}' }, {}), /capture_command_invalid: expected a JSON array/);
  assert.throws(() => resolveCaptureConfig({ captureCommand: '["stub-capture","{url}"]' }, {}), /\{output\} placeholder/);
  assert.throws(() => resolveCaptureConfig({ captureCommand: '["stub-capture",""]' }, {}), /non-empty argv/);
  const fromFlag = resolveCaptureConfig({ captureCommand: JSON.stringify(stubCapture.command) }, {});
  assert.deepEqual(fromFlag, { command: stubCapture.command, receipt_schema: CAPTURE_RECEIPT_SCHEMA });
  assert.equal(CAPTURE_RECEIPT_SCHEMA, 'blocks-engine-capture/capture-receipt/v1');
  const fromEnv = resolveCaptureConfig({}, { [CAPTURE_COMMAND_ENV]: JSON.stringify(['env-capture', '{url}', '{output}']), [CAPTURE_RECEIPT_SCHEMA_ENV]: 'example-capture/capture-receipt/v2' });
  assert.deepEqual(fromEnv, { command: ['env-capture', '{url}', '{output}'], receipt_schema: 'example-capture/capture-receipt/v2' });
  assert.deepEqual(resolveCaptureConfig({ capture: stubCapture }, { [CAPTURE_COMMAND_ENV]: '["ignored","{url}","{output}"]' }).command, stubCapture.command, 'explicit configuration wins over the environment');
  assert.throws(() => resolveCaptureConfig({ capture: { ...stubCapture, receipt_schema: 'not a schema' } }, {}), /capture_receipt_schema_invalid/);
});

test('the receipt schema check follows the configured schema', () => {
  const receipt = { schema: 'example-capture/capture-receipt/v2', source: { url: 'https://example.com/' }, summary: { complete: true, routesCaptured: 1, routesDiscovered: 1, routesFailed: 0, routesSkipped: 0 } };
  assert.deepEqual(validateCapture(receipt).failures, ['capture_receipt_schema_unknown']);
  assert.equal(validateCapture(receipt, { schema: 'example-capture/capture-receipt/v2' }).valid, true);
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
  const result = await runUrlLoopIntake({ url: 'https://example.com', outputRoot, capture: stubCapture, runtimeToolchain: { node: stubToolchain() } }, {
    spawn(command, args) {
      assert.match(command, /\/stub-capture$/);
      assert.deepEqual(args.slice(0, 2), ['--source', 'https://example.com/']);
      const captureRoot = outputOf(args);
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
  assert.deepEqual(result.identities.capture.command, stubCapture.command);
  assert.match(result.identities.capture.command_sha256, /^sha256:[a-f0-9]{64}$/);
  assert.equal(result.identities.capture.receipt_schema, CAPTURE_RECEIPT_SCHEMA);
});

test('capture launcher, child exit, and timeout failures retain bounded typed spawn evidence', async () => {
  for (const [result, reason, code, status, signal] of [
    [{ status: null, error: { code: 'ENOENT', message: 'spawnSync stub-capture ENOENT' } }, 'launcher_unavailable', 'ENOENT', null, null],
    [{ status: 7, signal: null }, 'child_nonzero_exit', null, 7, null],
    [{ status: null, signal: 'SIGTERM', error: { code: 'ETIMEDOUT', message: 'timed out' } }, 'command_timeout', 'ETIMEDOUT', null, 'SIGTERM'],
    [{ status: null, signal: 'SIGTERM' }, 'child_signaled', null, null, 'SIGTERM'],
    [{ status: null, error: { code: 'EACCES', message: 'permission denied' } }, 'spawn_failed', 'EACCES', null, null],
  ]) {
    const outputRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-url-loop-failure-'));
    const handoff = await runUrlLoopIntake({ url: 'https://example.com/', outputRoot, capture: stubCapture, runtimeToolchain: { node: stubToolchain() } }, { spawn: () => result });
    assert.equal(handoff.runtime_failure.reason, reason);
    assert.equal(handoff.runtime_failure.error_code, code);
    assert.equal(handoff.runtime_failure.exit_status, status);
    assert.equal(handoff.runtime_failure.signal, signal);
    assert.equal(handoff.status, 'blocked');
  }
});

test('a configured capture command runs for real with only standard system PATH entries', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-url-loop-toolchain-'));
  const launcher = path.join(root, 'stub-capture');
  const source = `#!/usr/bin/env node\nconst fs=require('node:fs');const path=require('node:path');const args=process.argv.slice(2);const output=args[args.indexOf('--into')+1];const site=path.join(output,'example.com');fs.mkdirSync(path.join(site,'website'),{recursive:true});fs.writeFileSync(path.join(site,'capture-receipt.json'),JSON.stringify({schema:'${CAPTURE_RECEIPT_SCHEMA}',source:{url:'https://example.com/'},summary:{complete:true,routesCaptured:1,routesDiscovered:1,routesFailed:0,routesSkipped:0}}));fs.writeFileSync(path.join(site,'website','index.html'),'<main>captured</main>');`;
  fs.writeFileSync(launcher, source, { mode: 0o755 });
  const outputRoot = path.join(root, 'output');
  const result = await runUrlLoopIntake({ url: 'https://example.com/', outputRoot, capture: { command: [launcher, '--source', '{url}', '--into', '{output}'] }, runtimeToolchain: { node: process.execPath } });
  assert.equal(result.status, 'needs_evaluation');
  assert.equal(result.failures.length, 0);
  assert.equal(result.capture_receipt.summary.routesCaptured, 1);
  assert.equal(fs.readFileSync(path.join(result.fixture.directory, 'index.html'), 'utf8'), '<main>captured</main>');
});

test('missing declared Node and capture executable fail before capture with precise prerequisites', async () => {
  for (const kind of ['node', 'command']) {
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-toolchain-missing-'));
    let spawned = false;
    const runtimeToolchain = { node: kind === 'node' ? path.join(root, 'missing-node') : process.execPath };
    const capture = { command: [kind === 'node' ? 'stub-capture' : 'missing-capture-command', '{url}', '{output}'] };
    const handoff = await runUrlLoopIntake({ url: 'https://example.com/', outputRoot: root, capture, runtimeToolchain }, { spawn: () => { spawned = true; return { status: 0 }; } });
    assert.equal(spawned, false);
    assert.equal(handoff.runtime_failure.reason, kind === 'node' ? 'node_unavailable' : 'launcher_unavailable');
    assert.equal(handoff.runtime_failure.error_code, 'ENOENT');
    assert.equal(handoff.runtime_failure.outcome, 'prerequisite_unavailable');
  }
});

test('dry run does not invoke the capture command or SSI materialization and remains blocked', async () => {
  let invoked = false;
  const outputRoot = path.join(os.tmpdir(), `ssi-url-loop-dry-${Date.now()}`);
  const result = await runUrlLoopIntake({ url: 'https://example.com', outputRoot, dryRun: true, capture: stubCapture }, {
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

test('initial URL intake and candidate replay share one canonical summarizable matrix failure path', async () => {
  const outputRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-shared-url-matrix-'));
  const summary = {
    schema: 'static-site-importer/fixture-matrix-operator-summary/v1', status: 'failed',
    matrix_evidence_readiness: { schema: 'static-site-importer/fixture-matrix-runtime-evidence-summary/v1', status: 'verified', fixtures: [{ fixture_id: 'website', readiness: 'verified', missing: [] }] },
    gate_failure_reasons: [{ fixture_id: 'website', category: 'visual_mismatch' }],
  };
  const matrixModule = {
    buildFixtureMatrixRunPlan: (input) => ({ output_file: input.output, steps: [{ retry_command: 'homeboy bench ...' }] }),
    summarizeBenchRun: () => ({ gateFailed: true, summary }),
  };
  const shared = await runUrlLoopMatrix({ fixtureRoot: '/tmp/fixtures', fixtureId: 'website', staticSiteImporter: '/tmp/ssi', blocksEngine: '/tmp/blocks-engine', outputRoot }, { matrixModule, spawn: () => ({ status: 1 }) });
  assert.equal(shared.matrix.status, 'failed');
  assert.equal(shared.matrix.evidence_complete, true);
  assert.deepEqual(shared.failures, [], 'a measured visual gate failure is not a runtime-command crash');

  let calls = 0;
  const handoff = await runUrlLoopIntake({ url: 'https://example.com/', outputRoot: path.join(outputRoot, 'intake'), capture: stubCapture, runtimeToolchain: { node: stubToolchain() }, runMatrix: true, staticSiteImporter: '/tmp/ssi', blocksEngine: '/tmp/blocks-engine' }, {
    matrixModule,
    spawn(command, args) {
      calls += 1;
      if (calls === 1) {
        const captureRoot = outputOf(args);
        const site = path.join(captureRoot, 'example.com');
        fs.mkdirSync(path.join(site, 'website'), { recursive: true });
        fs.writeFileSync(path.join(site, 'website', 'index.html'), '<main>one route</main>');
        fs.writeFileSync(path.join(site, 'capture-receipt.json'), JSON.stringify({ schema: CAPTURE_RECEIPT_SCHEMA, source: { url: 'https://example.com/' }, summary: { complete: true, routesCaptured: 1, routesDiscovered: 1, routesFailed: 0, routesSkipped: 0 } }));
      }
      return { status: calls === 1 ? 0 : 1 };
    },
  });
  assert.equal(calls, 2);
  assert.equal(handoff.status, 'needs_review');
  assert.equal(handoff.matrix.summary, summary);
  assert.deepEqual(handoff.failures, []);
  assert.equal(handoff.acceptance.solved, false);
});

test('missing canonical matrix result remains a typed hard blocker for both callers', async () => {
  const outputRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-url-matrix-crash-'));
  const shared = await runUrlLoopMatrix({ fixtureRoot: '/tmp/fixtures', fixtureId: 'website', staticSiteImporter: '/tmp/ssi', blocksEngine: '/tmp/blocks-engine', outputRoot }, {
    matrixModule: { buildFixtureMatrixRunPlan: () => ({ output_file: path.join(outputRoot, 'result.json'), steps: [] }), summarizeBenchRun: () => { throw new Error('bench output missing'); } },
    spawn: () => ({ status: 1 }),
  });
  assert.equal(shared.matrix.status, 'blocked');
  assert.equal(shared.summary, null);
  assert.equal(shared.failures[0].stage, 'matrix');
  assert.equal(shared.failures[0].reason, 'command_failed');
});
