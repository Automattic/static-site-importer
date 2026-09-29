import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { CAPTURE_ARTIFACT_SCHEMA, EVALUATION_ARTIFACT_SCHEMA, buildUrlLoopSpec, candidatePolicy, evaluateMatrixSummary, runCapture, runEvaluation, sourceIdentity, verifyCandidateWorkspace } from './url-loop-controller.mjs';

const url = 'https://example.com/';
const sha = 'a'.repeat(40);
const provenance = { source_url_sha256: `sha256:${'1'.repeat(64)}`, captured_content_sha256: `sha256:${'2'.repeat(64)}`, capture_receipt_sha256: `sha256:${'3'.repeat(64)}` };
function context(root) {
  return buildUrlLoopSpec({ url, workspace: root, outputRoot: path.join(root, 'output'), blocksEngine: root, wpCodeboxBin: path.join(root, 'codebox'), maxActions: 4 });
}
function capture(root, properties = {}) {
  const handoffPath = path.join(root, 'output', 'capture-intake', 'url-loop-handoff.json');
  const handoff = {
    url, status: 'needs_evaluation', fixture: { id: 'website', fixture_root: path.join(root, 'fixtures') },
    capture_receipt: { schema: 'data-liberation/capture-receipt/v1', source: { url }, summary: { complete: true, routesDiscovered: 2, routesCaptured: 2, routesFailed: 0, routesSkipped: 0 } },
    provenance, identities: { dla: { version: 'v0.6.5' } }, failures: [], handoff_path: handoffPath,
    ...properties,
  };
  fs.mkdirSync(path.dirname(handoffPath), { recursive: true });
  fs.writeFileSync(handoffPath, JSON.stringify(handoff));
  return handoff;
}

test('one URL yields a stable bounded Homeboy controller with typed capture-to-evaluation lineage', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-controller-spec-'));
  const { spec, context: inputs } = context(root);
  assert.equal(spec.schema, 'homeboy/controller-spec/v1');
  assert.equal(spec.controller_id, sourceIdentity(url));
  assert.equal(spec.controller_id, sourceIdentity('https://example.com/#fragment'));
  assert.equal(spec.workflows[0].runtime_execution.kind, 'command');
  assert.deepEqual(spec.workflows[0].emits, ['capture']);
  assert.deepEqual(spec.workflows[1].consumes, ['capture']);
  assert.equal(spec.artifact_graph[0].required, true);
  assert.equal(spec.actions, undefined, 'an initial wait must not block capture and evaluation');
  assert.equal(inputs.max_actions, 4);
  assert.throws(() => buildUrlLoopSpec({ url, workspace: root, blocksEngine: root, wpCodeboxBin: root, maxActions: 100 }), /max-actions/);
});

test('complete DLA capture is retained once and handed to Homeboy without claiming solved', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-controller-capture-'));
  const { context: inputs } = context(root);
  let count = 0;
  const { capture: artifact } = await runCapture(inputs, { capture: async () => { ++count; return capture(root); } });
  assert.equal(count, 1);
  assert.equal(artifact.schema, CAPTURE_ARTIFACT_SCHEMA);
  assert.equal(artifact.routes, 2);
  assert.equal(artifact.provenance.captured_content_sha256, provenance.captured_content_sha256);
  await assert.rejects(runCapture(inputs, { capture: async () => capture(root, { capture_receipt: { schema: 'unknown' } }) }), /capture_blocked/);
});

test('matrix findings remain actionable with incomplete evidence but never become solved', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-controller-evaluate-'));
  const { context: inputs } = context(root);
  const { capture: artifact } = await runCapture(inputs, { capture: async () => capture(root) });
  const summary = { schema: 'static-site-importer/fixture-matrix-operator-summary/v1', status: 'failed', matrix_evidence_readiness: { schema: 'static-site-importer/fixture-matrix-runtime-evidence-summary/v1', status: 'incomplete', fixtures: [{ fixture_id: 'website', readiness: 'runtime_evidence_incomplete', missing: ['transformer_reference'] }] }, gate_failure_reasons: [{ category: 'visual_mismatch', fixture_id: 'website', source_path: 'website/index.html' }], run_refs: { homeboy_run_id: 'matrix-1' } };
  const actual = await runEvaluation({ inputs: { ...inputs, candidate_sha: sha, artifacts: { capture: artifact } } }, {
    matrixModule: { buildFixtureMatrixRunPlan: () => ({ output_file: 'result.json', steps: [] }), summarizeBenchRun: () => ({ summary }) },
    spawn: () => ({ status: 1 }),
  });
  assert.equal(actual.evaluation.schema, EVALUATION_ARTIFACT_SCHEMA);
  assert.equal(actual.evaluation.status, 'needs_repair');
  assert.equal(actual.evaluation.acceptance.solved, false);
  assert.ok(actual.evaluation.missing.includes('transformer_reference'));
  assert.equal(actual.evaluation.findings[0].category, 'visual_mismatch');
  assert.equal(actual.evaluation.source_url_sha256, provenance.source_url_sha256);
  const saved = JSON.parse(fs.readFileSync(path.join(inputs.root, 'evaluations', sha, 'action-1', 'evaluation.json')));
  assert.equal(saved.candidate_sha, sha);
  await assert.rejects(runEvaluation({ inputs: { ...inputs, candidate_sha: sha, artifacts: { capture: { ...artifact, provenance: { ...provenance, captured_content_sha256: 'different' } } } } }, {}), /capture_handoff_identity_mismatch/);
});

test('absent matrix evidence is a typed blocker; zero fallback never implies solved', () => {
  const artifact = { fixture_id: 'website', routes: 1, provenance };
  assert.equal(evaluateMatrixSummary({ summary: null, capture: artifact, candidateSha: sha, runId: 'r1' }).status, 'blocked');
  const evaluated = evaluateMatrixSummary({ summary: { status: 'passed', fallback_count: 0, matrix_evidence_readiness: { fixtures: [{ fixture_id: 'website', readiness: 'verified', missing: [] }] } }, benchStatus: 0, capture: artifact, candidateSha: sha, runId: 'r1' });
  assert.equal(evaluated.status, 'needs_evidence');
  assert.ok(evaluated.missing.includes('visual_viewport_390_missing'));
  assert.equal(evaluated.acceptance.solved, false);
});

test('candidate event routes one capture-bound re-evaluation per immutable candidate SHA', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-controller-candidate-'));
  const { context: inputs } = context(root);
  const artifact = { provenance };
  const a = candidatePolicy(inputs, artifact, sha);
  const duplicate = candidatePolicy(inputs, artifact, sha);
  const changed = candidatePolicy(inputs, artifact, 'b'.repeat(40));
  assert.deepEqual(a, duplicate);
  assert.notEqual(a.transitions[0].actions[0].dedupe_key, changed.transitions[0].actions[0].dedupe_key);
  assert.equal(a.transitions[0].actions[0].request.consumes[0], 'capture');
  assert.throws(() => candidatePolicy(inputs, artifact, 'short'), /full Git commit/);
});

test('candidate events bind a clean Git checkout to the exact supplied revision', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-loop-candidate-worktree-'));
  const git = (...args) => {
    const result = spawnSync('git', args, { cwd: root, encoding: 'utf8' });
    assert.equal(result.status, 0, result.stderr);
    return result.stdout.trim();
  };
  git('init', '-b', 'main');
  fs.writeFileSync(path.join(root, 'candidate.txt'), 'version one\n');
  git('add', 'candidate.txt');
  git('-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.com', 'commit', '-m', 'candidate');
  const commit = git('rev-parse', 'HEAD');
  assert.equal(verifyCandidateWorkspace(root, commit), root);
  assert.throws(() => verifyCandidateWorkspace(root, 'a'.repeat(40)), /candidate_workspace_commit_mismatch/);
  fs.writeFileSync(path.join(root, 'candidate.txt'), 'uncommitted mutation\n');
  assert.throws(() => verifyCandidateWorkspace(root, commit), /candidate_workspace_not_clean/);
});

test('managed Homeboy WorkJob persists capture, evaluates, and resumes one deduped candidate recheck', async () => {
  const workspace = path.dirname(path.dirname(fileURLToPath(import.meta.url)));
  const home = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-homeboy-workjob-'));
  const { spec, context: inputs } = buildUrlLoopSpec({ url, workspace, outputRoot: path.join(home, 'loop'), blocksEngine: workspace, wpCodeboxBin: '/usr/bin/true' });
  const captureScript = `require('node:fs').writeFileSync(process.env.HOMEBOY_LOOP_ACTION_OUTPUT,JSON.stringify({artifacts:{capture:{schema:'${CAPTURE_ARTIFACT_SCHEMA}',url:'${url}',provenance:{captured_content_sha256:'${provenance.captured_content_sha256}'}}}}))`;
  const evaluateScript = `const fs=require('node:fs');const input=JSON.parse(fs.readFileSync(process.env.HOMEBOY_LOOP_ACTION_INPUT,'utf8'));if(input.request.inputs.artifacts?.capture?.url!=='${url}')process.exit(2);fs.writeFileSync(process.env.HOMEBOY_LOOP_ACTION_OUTPUT,JSON.stringify({artifacts:{evaluation:{schema:'${EVALUATION_ARTIFACT_SCHEMA}',acceptance:{solved:false}}}}))`;
  for (const [index, script] of [captureScript, evaluateScript].entries()) spec.workflows[index].runtime_execution = { kind: 'command', command: process.execPath, args: ['-e', script], cwd: workspace, timeout_seconds: 10 };
  const env = { ...process.env, HOME: home, XDG_CONFIG_HOME: path.join(home, 'config'), XDG_DATA_HOME: path.join(home, 'data'), XDG_CACHE_HOME: path.join(home, 'cache'), HOMEBOY_ARTIFACT_ROOT: path.join(home, 'artifacts') };
  function homeboy(args) {
    const result = spawnSync('homeboy', args, { cwd: workspace, env, encoding: 'utf8', timeout: 60000 });
    assert.equal(result.status, 0, `${result.stderr}\n${result.stdout}`);
    return JSON.parse(result.stdout);
  }
  homeboy(['agent-task', 'loop', 'define', JSON.stringify(spec), '--on', '--resume', '--revolution-limit', '4']);
  let first;
  for (let attempt = 0; attempt < 30; attempt += 1) {
    first = homeboy(['agent-task', 'loop', 'status', spec.controller_id]).data;
    if (first.status.controller.next_actions.filter((action) => action.status === 'completed').length === 2) break;
    await new Promise((resolve) => setTimeout(resolve, 300));
  }
  assert.equal(first.status.controller.next_actions.filter((action) => action.status === 'completed').length, 2);
  assert.ok(first.work.job_id, 'the actions belong to a durable Homeboy WorkJob');
  const policy = candidatePolicy(inputs, { provenance }, sha);
  policy.transitions[0].actions[0].request.execution.args = ['-e', evaluateScript];
  const event = ['agent-task', 'controller', 'events', spec.controller_id, '--event-type', 'ssi.candidate.updated', '--event-id', `candidate-${sha}`, '--event-key', sha, '--payload', JSON.stringify({ policy })];
  homeboy(event);
  homeboy(event);
  homeboy(['agent-task', 'loop', 'resume', spec.controller_id]);
  let status;
  for (let attempt = 0; attempt < 30; attempt += 1) {
    status = homeboy(['agent-task', 'controller', 'status', spec.controller_id]).data.controller;
    if (status.next_actions.filter((action) => action.status === 'completed').length === 3) break;
    await new Promise((resolve) => setTimeout(resolve, 300));
  }
  assert.equal(status.next_actions.filter((action) => action.status === 'completed').length, 3);
  assert.equal(status.next_actions.filter((action) => action.status === 'already_satisfied').length, 1);
  assert.equal(status.task_lineage.filter((item) => item.outputs.artifacts?.evaluation?.schema === EVALUATION_ARTIFACT_SCHEMA).length, 2);
});
