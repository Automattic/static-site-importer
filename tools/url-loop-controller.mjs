#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { DLA_RELEASE, runUrlLoopIntake, validateCapture } from './url-loop-intake.mjs';

export const CAPTURE_ARTIFACT_SCHEMA = 'static-site-importer/url-loop-capture/v1';
export const EVALUATION_ARTIFACT_SCHEMA = 'static-site-importer/url-loop-evaluation/v1';
const CONTROLLER_SCHEMA = 'homeboy/controller-spec/v1';
const MODULE_PATH = fileURLToPath(import.meta.url);
const SOURCE_VIEWS = [390, 768, 1440];

function digest(value) { return crypto.createHash('sha256').update(value).digest('hex'); }
function normalizeUrl(value) {
  const url = new URL(String(value || ''));
  if (!['https:', 'http:'].includes(url.protocol)) throw new Error('url must use http(s)');
  url.hash = '';
  return url.href;
}
function readJson(file) { return JSON.parse(fs.readFileSync(file, 'utf8')); }
function writeJson(file, value) { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`); }
function requiredCommit(value, name) {
  if (!/^[a-f0-9]{40}$/.test(String(value || ''))) throw new Error(`${name} requires a full Git commit SHA`);
  return value;
}

export function sourceIdentity(url) {
  return `ssi-url-${digest(normalizeUrl(url)).slice(0, 20)}`;
}

export function buildUrlLoopSpec(input) {
  const url = normalizeUrl(input.url);
  const blocksEngine = input.blocksEngine || process.env.SSI_BLOCKS_ENGINE_PATH;
  const wpCodeboxBin = input.wpCodeboxBin || process.env.HOMEBOY_WP_CODEBOX_BIN;
  if (!blocksEngine || !wpCodeboxBin) throw new Error('blocks-engine and wp-codebox-bin must be configured on this runner');
  const maxActions = Number(input.maxActions ?? 4);
  if (!Number.isInteger(maxActions) || maxActions < 3 || maxActions > 10) throw new Error('max-actions must be an integer between 3 and 10');
  const sourceId = sourceIdentity(url);
  if (input.instance && !/^[A-Za-z0-9_-]{1,32}$/.test(input.instance)) throw new Error('instance must be a short path-safe token');
  const loopId = input.instance ? `${sourceId}-${input.instance}` : sourceId;
  const workspace = path.resolve(input.workspace || process.cwd());
  const root = path.resolve(input.outputRoot || path.join(workspace, 'artifacts', 'url-loop', loopId));
  const context = { url, loop_id: loopId, source_id: sourceId, root, workspace, blocks_engine: path.resolve(blocksEngine), wp_codebox_bin: path.resolve(wpCodeboxBin), transformer_path: input.transformerPath ? path.resolve(input.transformerPath) : '', candidate_sha: input.candidateSha || process.env.SSI_CANDIDATE_SHA || null, blocks_engine_sha: input.blocksEngineSha || null, wordpress_version: input.wordpressVersion || null, max_actions: maxActions };
  const action = (stage, timeout, artifacts, consumes = []) => ({
    workflow_id: stage,
    tasks: [stage === 'capture' ? 'Retain the source URL capture and normalized SSI fixture.' : 'Evaluate the retained fixture in disposable WordPress and report browser findings.'],
    runtime_execution: { kind: 'command', command: process.execPath, args: [MODULE_PATH, stage], cwd: workspace, timeout_seconds: timeout },
    artifacts, emits: artifacts, consumes,
    inputs: context,
  });
  const spec = {
    schema: CONTROLLER_SCHEMA,
    controller_id: loopId,
    phase: 'evaluate',
    config_version: 'ssi-url-loop-v1',
    metadata: { source_url_sha256: `sha256:${digest(url)}`, dispatch_defaults: { cwd: workspace, repo: 'static-site-importer' }, max_actions: context.max_actions },
    artifacts: [
      { artifact_id: 'capture', kind: CAPTURE_ARTIFACT_SCHEMA, required: true },
      { artifact_id: 'evaluation', kind: EVALUATION_ARTIFACT_SCHEMA, required: true },
    ],
    workflows: [action('capture', 900, ['capture']), action('evaluate', 5400, ['evaluation'], ['capture'])],
    artifact_graph: [{ artifact_id: 'capture', from_workflow_id: 'capture', to_workflow_id: 'evaluate', required: true }],
  };
  return { spec, context };
}

function captureArtifact(handoff) {
  if (handoff.status !== 'needs_evaluation' || !handoff.fixture?.fixture_root || !validateCapture(handoff.capture_receipt).valid || handoff.capture_receipt?.source?.url !== handoff.url || !handoff.provenance?.captured_content_sha256) {
    throw new Error(`capture_blocked:${JSON.stringify(handoff.failures || [])}`);
  }
  return {
    schema: CAPTURE_ARTIFACT_SCHEMA,
    url: handoff.url,
    fixture_id: handoff.fixture.id,
    fixture_root: handoff.fixture.fixture_root,
    handoff_path: handoff.handoff_path,
    provenance: handoff.provenance,
    routes: handoff.capture_receipt.summary.routesCaptured,
    dla: handoff.identities.dla,
  };
}

export async function runCapture(context, dependencies = {}) {
  const handoff = await (dependencies.capture || runUrlLoopIntake)({ url: context.url, outputRoot: path.join(context.root, 'capture-intake') });
  return { capture: captureArtifact(handoff) };
}

export function evaluateMatrixSummary({ summary, benchStatus, capture, candidateSha, runId }) {
  const readiness = summary?.matrix_evidence_readiness;
  const row = readiness?.fixtures?.find((item) => item.fixture_id === capture.fixture_id);
  const observedViewports = summary?.surface_coverage?.viewports || [];
  const missing = [...new Set([...(row?.missing || []), ...(row?.readiness === 'verified' ? [] : ['runtime_evidence_incomplete']), 'solved_site_promotion_receipt', ...SOURCE_VIEWS.filter((width) => !observedViewports.includes(width)).map((width) => `visual_viewport_${width}_missing`)])];
  const findings = Array.isArray(summary?.gate_failure_reasons) ? summary.gate_failure_reasons : [];
  return {
    schema: EVALUATION_ARTIFACT_SCHEMA,
    status: !summary ? 'blocked' : findings.length ? 'needs_repair' : missing.length ? 'needs_evidence' : benchStatus !== 0 ? 'needs_repair' : 'needs_review',
    source_url_sha256: capture.provenance.source_url_sha256,
    captured_content_sha256: capture.provenance.captured_content_sha256,
    candidate_sha: candidateSha,
    run_id: runId,
    fixture_id: capture.fixture_id,
    routes: capture.routes,
    required_viewports: SOURCE_VIEWS,
    observed_viewports: observedViewports,
    evidence_readiness: readiness || null,
    missing,
    findings,
    matrix_refs: summary?.run_refs || null,
    matrix_output_file: summary?.output_file || null,
    acceptance: { solved: false, reason: 'solved_site_promotion_receipt_required' },
  };
}

export async function runEvaluation(request, dependencies = {}) {
  const context = request.inputs;
  const capture = context?.artifacts?.capture;
  if (capture?.schema !== CAPTURE_ARTIFACT_SCHEMA || capture.url !== normalizeUrl(context.url) || !fs.existsSync(capture.handoff_path)) throw new Error('capture_handoff_missing');
  const handoff = readJson(capture.handoff_path);
  if (handoff.provenance?.captured_content_sha256 !== capture.provenance.captured_content_sha256 || handoff.provenance?.source_url_sha256 !== capture.provenance.source_url_sha256 || handoff.fixture?.fixture_root !== capture.fixture_root) throw new Error('capture_handoff_identity_mismatch');
  const candidateSha = requiredCommit(context.candidate_sha || (dependencies.commit || gitCommit)(context.workspace), 'candidate_sha');
  const observedCommit = (dependencies.observedCommit || gitCommitIfAvailable)(context.workspace);
  if (observedCommit && observedCommit !== candidateSha) throw new Error('candidate_workspace_commit_mismatch');
  const actionId = String(request.action_id || 'action-1');
  if (!/^action-[0-9]+$/.test(actionId)) throw new Error('controller_action_identity_invalid');
  const runId = `${context.loop_id}-matrix-${candidateSha.slice(0, 12)}-${actionId}`;
  const output = path.join(context.root, 'evaluations', candidateSha, actionId, 'homeboy-bench-result.json');
  if (fs.existsSync(output)) throw new Error('matrix_output_not_fresh');
  fs.mkdirSync(path.dirname(output), { recursive: true });
  const matrixInput = { fixtureRoot: capture.fixture_root, targetFixture: capture.fixture_id, staticSiteImporter: context.workspace, blocksEngine: context.blocks_engine, blocksEnginePhpTransformerPath: context.transformer_path || context.blocks_engine, runId, output, local: true, surfaceCoverage: Math.min(10, capture.routes - 1) };
  const { buildFixtureMatrixRunPlan, summarizeBenchRun } = await (dependencies.matrixModule || import('./run-fixture-matrix.mjs'));
  const plan = buildFixtureMatrixRunPlan(matrixInput);
  const args = ['tools/run-fixture-matrix.mjs', '--static-site-importer', context.workspace, '--blocks-engine', context.blocks_engine, '--blocks-engine-php-transformer-path', matrixInput.blocksEnginePhpTransformerPath, '--fixture-root', capture.fixture_root, '--target-fixture', capture.fixture_id, '--run-id', runId, '--output', output, '--wp-codebox-bin', context.wp_codebox_bin, '--surface-coverage', String(matrixInput.surfaceCoverage), '--local', '--skip-install', '--skip-sync'];
  const result = (dependencies.spawn || spawnSync)(process.execPath, args, { cwd: context.workspace, stdio: 'inherit' });
  let summary = null;
  try { summary = summarizeBenchRun({ plan, benchStatus: result.status ?? 1 }).summary; } catch { /* Missing authoritative result is a typed blocker below. */ }
  const evaluation = evaluateMatrixSummary({ summary, benchStatus: result.status, capture, candidateSha, runId });
  evaluation.versions = { dla: capture.dla, ssi: candidateSha, blocks_engine: context.blocks_engine_sha || null, wordpress: context.wordpress_version || null, browser: summary?.lane_identity?.browser || null };
  if (!observedCommit) evaluation.missing.push('candidate_commit_unverified');
  evaluation.command = { command: process.execPath, args };
  writeJson(path.join(context.root, 'evaluations', candidateSha, actionId, 'evaluation.json'), evaluation);
  if (evaluation.status === 'blocked') throw new Error(`matrix_runtime_blocked:${result.status ?? 'signal'}`);
  return { evaluation };
}

function gitCommit(cwd) {
  const result = spawnSync('git', ['rev-parse', 'HEAD'], { cwd, encoding: 'utf8' });
  if (result.status !== 0) throw new Error('ssi_commit_unavailable');
  return result.stdout.trim();
}

function gitCommitIfAvailable(cwd) {
  const result = spawnSync('git', ['rev-parse', 'HEAD'], { cwd, encoding: 'utf8' });
  return result.status === 0 && /^[a-f0-9]{40}$/.test(result.stdout.trim()) ? result.stdout.trim() : null;
}

export function verifyCandidateWorkspace(workspace, candidateSha) {
  const root = path.resolve(workspace);
  const sha = requiredCommit(candidateSha, 'candidate_sha');
  if (gitCommitIfAvailable(root) !== sha) throw new Error('candidate_workspace_commit_mismatch');
  const status = spawnSync('git', ['status', '--porcelain'], { cwd: root, encoding: 'utf8' });
  if (status.status !== 0 || status.stdout.trim()) throw new Error('candidate_workspace_not_clean');
  return root;
}

export function candidatePolicy(context, capture, candidateSha) {
  const sha = requiredCommit(candidateSha, 'candidate_sha');
  const dedupeKey = `evaluate:${capture.provenance.captured_content_sha256}:${sha}`;
  const request = { mode: 'command', consumes: ['capture'], artifacts: ['evaluation'], inputs: { ...context, candidate_sha: sha }, execution: { kind: 'command', command: process.execPath, args: [MODULE_PATH, 'evaluate'], cwd: context.workspace, timeout_seconds: 5400 } };
  return { policy_id: 'ssi-url-candidate-evaluation', transitions: [{ transition_id: 'candidate-recheck', on_event_type: 'ssi.candidate.updated', actions: [{ action: 'run_command', dedupe_key: dedupeKey, request }] }] };
}

function parseArgs(argv) {
  const options = {};
  for (let i = 0; i < argv.length; i += 1) if (argv[i].startsWith('--')) options[argv[i].slice(2).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = argv[++i];
  return options;
}

async function main() {
  const [operation, ...argv] = process.argv.slice(2);
  const options = parseArgs(argv);
  if (operation === 'capture' || operation === 'evaluate') {
    const input = readJson(process.env.HOMEBOY_LOOP_ACTION_INPUT);
    const artifacts = operation === 'capture' ? await runCapture(input.request.inputs) : await runEvaluation(input.request);
    writeJson(process.env.HOMEBOY_LOOP_ACTION_OUTPUT, { artifacts });
    return;
  }
  if (operation === 'start') {
    const { spec, context } = buildUrlLoopSpec(options);
    context.candidate_sha = requiredCommit(context.candidate_sha || gitCommit(context.workspace), 'candidate_sha');
    const observedCommit = gitCommitIfAvailable(context.workspace);
    if (observedCommit && observedCommit !== context.candidate_sha) throw new Error('candidate_workspace_commit_mismatch');
    for (const workflow of spec.workflows) workflow.inputs.candidate_sha = context.candidate_sha;
    writeJson(path.join(context.root, 'loop-context.json'), context);
    const result = spawnSync(options.homeboyBin || 'homeboy', ['agent-task', 'loop', 'define', JSON.stringify(spec), '--on', '--resume', '--revolution-limit', String(context.max_actions)], { cwd: context.workspace, stdio: 'inherit' });
    if (result.status !== 0) throw new Error(`loop_admission_failed:${result.status}`);
    process.stdout.write(`${JSON.stringify({ loop_id: context.loop_id, status_command: `homeboy agent-task loop status ${context.loop_id}`, context_file: path.join(context.root, 'loop-context.json') })}\n`);
    return;
  }
  if (operation === 'candidate') {
    const context = readJson(path.join(path.resolve(options.outputRoot), 'loop-context.json'));
    const handoffPath = path.join(context.root, 'capture-intake', 'url-loop-handoff.json');
    const capture = captureArtifact({ ...readJson(handoffPath), handoff_path: handoffPath });
    const sha = requiredCommit(options.candidateSha, 'candidate_sha');
    const candidateWorkspace = verifyCandidateWorkspace(options.candidateWorkspace || context.workspace, sha);
    const eventId = `candidate-${sha}-${capture.provenance.captured_content_sha256.slice(-12)}`;
    const payload = JSON.stringify({ source_url_sha256: capture.provenance.source_url_sha256, captured_content_sha256: capture.provenance.captured_content_sha256, policy: candidatePolicy({ ...context, workspace: candidateWorkspace }, capture, sha) });
    const result = spawnSync(options.homeboyBin || 'homeboy', ['agent-task', 'controller', 'events', context.loop_id, '--event-type', 'ssi.candidate.updated', '--event-id', eventId, '--event-key', sha, '--payload', payload], { cwd: context.workspace, stdio: 'inherit' });
    if (result.status !== 0) throw new Error(`candidate_event_failed:${result.status}`);
    const resumed = spawnSync(options.homeboyBin || 'homeboy', ['agent-task', 'loop', 'resume', context.loop_id], { cwd: context.workspace, stdio: 'inherit' });
    if (resumed.status !== 0) throw new Error(`candidate_resume_failed:${resumed.status}`);
    return;
  }
  if (operation === 'spec') { process.stdout.write(`${JSON.stringify(buildUrlLoopSpec(options).spec, null, 2)}\n`); return; }
  throw new Error('usage: url-loop-controller.mjs start|candidate|spec --url <url> --blocks-engine <path> --wp-codebox-bin <path> [--output-root <path>]');
}

if (process.argv[1] === MODULE_PATH) main().catch((error) => { process.stderr.write(`${error.message}\n`); process.exitCode = 1; });
