#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { materializeGeneratedArtifactFixtures, discoverGeneratedArtifacts } from '../lib/artifact-intake.mjs';

export const URL_LOOP_INTAKE_SCHEMA = 'static-site-importer/url-loop-intake/v1';
export const CAPTURE_RECEIPT_SCHEMA = 'data-liberation/capture-receipt/v1';
export const MATRIX_EVIDENCE_READINESS_SCHEMA = 'static-site-importer/fixture-matrix-runtime-evidence-summary/v1';
export const DLA_RELEASE = Object.freeze({
  version: 'v0.6.5',
  commit: '880575b81cd837322520d05d260f5d682506afbb',
  asset: 'https://github.com/Automattic/data-liberation-agent/releases/download/v0.6.5/data-liberation-0.6.5.tgz',
  sha256: 'dd6ce344a37fbe7e06af973aec0cf576cdb2fc88f3a4e155e49723953c4200b8',
});

export function validateCapture(input = {}) {
  const receipt = input.receipt || input;
  const failures = [];
  if (receipt.schema !== CAPTURE_RECEIPT_SCHEMA) failures.push('capture_receipt_schema_unknown');
  const summary = receipt.summary;
  if (summary?.complete !== true || !Number.isInteger(summary?.routesCaptured) || summary.routesCaptured < 1 || summary.routesCaptured !== summary.routesDiscovered || summary.routesFailed !== 0 || summary.routesSkipped !== 0) {
    failures.push('capture_incomplete');
  }
  if (!receipt.source?.url) failures.push('capture_source_url_missing');
  if (!summary || !Number.isInteger(summary.routesDiscovered)) failures.push('capture_route_summary_missing');
  return { valid: failures.length === 0, failures };
}

export function validateArtifactSelection(artifactRoot) {
  const candidates = discoverGeneratedArtifacts(artifactRoot);
  if (candidates.length !== 1) {
    throw new Error(`capture_artifact_directory_ambiguous: expected exactly one generated artifact directory, found ${candidates.length}`);
  }
  return candidates[0];
}

export function createHandoff(input = {}) {
  const status = input.failures?.length
    ? 'blocked'
    : input.matrix?.evidence_complete
      ? 'needs_review'
      : input.matrix
        ? 'blocked'
        : 'needs_evaluation';
  const handoff = {
    schema: URL_LOOP_INTAKE_SCHEMA,
    status,
    url: input.url || '',
    provenance: input.provenance || null,
    capture_receipt: input.captureReceipt || null,
    identities: input.identities || {},
    fixture: input.fixture || null,
    matrix: input.matrix || null,
    finding_packet_refs: input.findingPacketRefs || [],
    failures: input.failures || [],
    commands: input.commands || [],
    runtime_failure: input.runtimeFailure || null,
    acceptance: {
      solved: false,
      reason: 'url_loop_intake_does_not certify solved-site acceptance',
    },
  };
  return handoff;
}

/** One SSI-owned matrix boundary for initial URL intake and candidate re-evaluation. */
export async function runUrlLoopMatrix(input, dependencies = {}) {
  if (!input.staticSiteImporter || !input.blocksEngine) throw new Error('matrix_component_identities_missing');
  const { buildFixtureMatrixRunPlan, summarizeBenchRun } = await (dependencies.matrixModule || import('./run-fixture-matrix.mjs'));
  const output = path.resolve(input.output || path.join(input.outputRoot, 'homeboy-bench-result.json'));
  const matrixInput = {
    ...(input.matrixOptions || {}),
    fixtureRoot: input.fixtureRoot,
    targetFixture: input.fixtureId,
    staticSiteImporter: input.staticSiteImporter,
    blocksEngine: input.blocksEngine,
    ssiIdentity: input.ssiIdentity,
    blocksEngineIdentity: input.blocksEngineIdentity,
    output,
  };
  const plan = buildFixtureMatrixRunPlan(matrixInput);
  const args = [path.join(path.dirname(fileURLToPath(import.meta.url)), 'run-fixture-matrix.mjs'), '--static-site-importer', input.staticSiteImporter, '--blocks-engine', input.blocksEngine, '--fixture-root', input.fixtureRoot, '--target-fixture', input.fixtureId, '--output', output, ...(input.matrixArgs || [])];
  const command = { stage: 'matrix', command: process.execPath, args };
  const matrix = { status: 'planned', plan, command: plan.steps.at(-1)?.retry_command || '' };
  const result = (dependencies.spawn || spawnSync)(process.execPath, args, { cwd: input.cwd || process.cwd(), stdio: 'inherit' });
  const failures = [];
  let summary = null;
  try { summary = summarizeBenchRun({ plan, benchStatus: result.status ?? 1 }).summary; } catch { /* A crash has no authoritative result. */ }
  if (summary?.matrix_evidence_readiness) {
    matrix.summary = summary;
    matrix.status = summary.status;
    matrix.artifact_refs = summary.artifact_urls || [];
    matrix.evidence = summary.matrix_evidence_readiness;
    const evidence = validateMatrixEvidence(summary, input.fixtureId);
    matrix.evidence_complete = evidence.valid;
    if (!evidence.valid) failures.push({ stage: 'matrix', reason: evidence.reason, fixture_id: input.fixtureId, readiness: matrix.evidence });
  } else {
    matrix.status = 'blocked';
    failures.push(matrixRuntimeFailure(result, output));
  }
  return { matrix, summary, result, failures, commands: [command] };
}

export async function runUrlLoopIntake(input = {}, dependencies = {}) {
  const url = normalizeUrl(input.url);
  const configInput = typeof input.dlaConfig === 'string' ? readJson(path.resolve(input.dlaConfig)) : (input.dlaConfig || input);
  const config = normalizeDlaConfig(configInput || {});
  const outputRoot = path.resolve(input.outputRoot || path.join(process.cwd(), 'artifacts', sourceSlug(url)));
  const captureRoot = path.join(outputRoot, 'capture');
  const fixtureRoot = path.join(outputRoot, 'fixtures', 'websites');
  const handoffPath = path.resolve(input.handoff || path.join(outputRoot, 'url-loop-handoff.json'));
  const commands = [];
  const failures = [];
  if (!input.dryRun) fs.mkdirSync(outputRoot, { recursive: true });
  if (input.dryRun) failures.push('dry_run_not_executed');
  if (fs.existsSync(outputRoot) && fs.readdirSync(outputRoot).length > 0 && !input.allowExistingOutput) failures.push('output_root_not_fresh');
  if (!failures.length) {
    fs.mkdirSync(captureRoot, { recursive: true });
    const captureArgs = config.captureArgs.map((arg) => String(arg).replaceAll('{url}', url).replaceAll('{output}', captureRoot));
    const command = { command: config.cli, args: captureArgs };
    commands.push({ stage: 'capture', ...command, shell: shellCommand(command) });
    const prerequisite = capturePrerequisiteFailure(config);
    if (prerequisite) failures.push(prerequisite);
    else {
      let result;
      try {
        result = (dependencies.spawn || spawnSync)(config.cli, captureArgs, {
          stdio: 'inherit', timeout: config.timeout,
          env: { ...process.env, ...(config.env || {}), PATH: config.path || process.env.PATH },
        });
      } catch (error) {
        result = { status: null, signal: null, error: { code: error.code || null, message: error.message } };
      }
      if (result.error || result.signal || result.status !== 0) failures.push(captureRuntimeFailure(result));
    }
  }

  const selectedCapture = findRetainedCapture(captureRoot, url);
  const captureReceipt = selectedCapture?.receipt || null;
  const captureValidation = validateCapture(captureReceipt || {});
  failures.push(...captureValidation.failures);
  if (!selectedCapture) failures.push('capture_directory_missing_or_ambiguous');
  if (captureReceipt && captureReceipt.source?.url !== url) failures.push('capture_source_url_mismatch');
  if (selectedCapture && captureReceipt) {
    const htmlCount = listFiles(path.join(selectedCapture.directory, 'website')).filter((file) => /\.html?$/i.test(file)).length;
    if (htmlCount < captureReceipt.summary.routesCaptured) failures.push('capture_route_files_missing');
  }
  const provenance = selectedCapture ? buildProvenance(url, selectedCapture) : null;
  let fixture;
  if (!failures.length) {
    try {
      validateArtifactSelection(path.join(selectedCapture.directory, 'website'));
      const intake = materializeGeneratedArtifactFixtures({ artifactRoot: path.join(selectedCapture.directory, 'website'), fixtureRoot });
      if (intake.count !== 1) throw new Error(`fixture_count_invalid:${intake.count}`);
      fixture = intake.fixtures[0];
    } catch (error) {
      failures.push(error.message);
    }
  }

  let matrix = null;
  if (!failures.length && input.runMatrix) {
    try {
      const evaluated = await runUrlLoopMatrix({ fixtureRoot, fixtureId: fixture.id, staticSiteImporter: input.staticSiteImporter, blocksEngine: input.blocksEngine, ssiIdentity: input.ssiIdentity, blocksEngineIdentity: input.blocksEngineIdentity, matrixOptions: input.matrix, matrixArgs: input.matrixArgs, output: path.join(outputRoot, 'matrix', 'homeboy-bench-result.json') }, dependencies);
      matrix = evaluated.matrix;
      failures.push(...evaluated.failures);
      commands.push(...evaluated.commands);
    } catch (error) {
      failures.push(`matrix_setup_failed:${error.message}`);
    }
  }
  const runtimeFailure = failures.find((failure) => failure?.stage === 'capture') || null;
  const handoff = createHandoff({ url, provenance, captureReceipt, fixture, matrix, failures, commands, runtimeFailure, findingPacketRefs: [], identities: { dla: config.identity, ssi: input.ssiIdentity, blocks_engine: input.blocksEngineIdentity, wordpress: input.wordpressIdentity } });
  if (input.dryRun) return { ...handoff, handoff_path: null };
  fs.mkdirSync(path.dirname(handoffPath), { recursive: true });
  fs.writeFileSync(handoffPath, `${JSON.stringify(handoff, null, 2)}\n`);
  return { ...handoff, handoff_path: handoffPath };
}

function normalizeUrl(value) { const url = new URL(String(value || '')); if (!['http:', 'https:'].includes(url.protocol)) throw new Error('url must be a public http(s) URL'); return url.href; }
function normalizeDlaConfig(input) {
  const identity = { ...DLA_RELEASE, executable: 'data-liberation' };
  if (input.dlaVersion && input.dlaVersion !== identity.version || input.dlaCommit && input.dlaCommit !== identity.commit || input.dlaAsset && input.dlaAsset !== identity.asset || input.dlaSha256 && input.dlaSha256 !== identity.sha256) throw new Error('declared DLA release identity does not match v0.6.5');
  const toolchain = input.runtimeToolchain || {};
  const node = path.resolve(toolchain.node || process.execPath);
  const launcher = path.resolve(toolchain.npx || path.join(path.dirname(node), 'npx'));
  const pathValue = [path.dirname(node), '/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin', '/sbin'].join(path.delimiter);
  return { cli: launcher, node, captureArgs: ['--yes', `--package=${identity.asset}`, identity.executable, '{url}', '--output', '{output}'], identity, path: pathValue, timeout: toolchain.timeout || 600000, env: toolchain.env || {} };
}
function capturePrerequisiteFailure(config) {
  for (const [executable, reason] of [[config.node, 'node_unavailable'], [config.cli, 'launcher_unavailable']]) {
    try { fs.accessSync(executable, fs.constants.X_OK); }
    catch (error) { return { stage: 'capture', reason, outcome: 'prerequisite_unavailable', exit_status: null, error_code: error.code || null, error_message: String(error.message).slice(0, 1024), signal: null }; }
  }
  return null;
}
function captureRuntimeFailure(result) {
  const timedOut = result?.error?.code === 'ETIMEDOUT';
  const unavailable = result?.error?.code === 'ENOENT';
  return { stage: 'capture', reason: timedOut ? 'command_timeout' : unavailable ? 'launcher_unavailable' : result?.error ? 'spawn_failed' : result?.signal ? 'child_signaled' : 'child_nonzero_exit', outcome: timedOut ? 'timed_out' : unavailable ? 'launcher_unavailable' : 'nonzero_or_missing_output', exit_status: result?.status ?? null, error_code: result?.error?.code || null, error_message: result?.error?.message ? String(result.error.message).slice(0, 1024) : null, signal: result?.signal || null };
}
function readJson(file) { try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return null; } }
function findRetainedCapture(root, url) {
  const candidates = [];
  let malformed = false;
  visitCaptureDirectories(root, (directory) => {
    const receiptPath = path.join(directory, 'capture-receipt.json');
    const website = path.join(directory, 'website');
    const hasReceipt = fs.existsSync(receiptPath);
    const hasWebsite = fs.existsSync(website) && fs.statSync(website).isDirectory();
    if (hasReceipt !== hasWebsite) { malformed = true; return; }
    if (!hasReceipt) return;
    const receipt = readJson(receiptPath);
    if (receipt?.source?.url === url) candidates.push({ directory, receipt, receiptPath });
  });
  if (malformed || candidates.length !== 1) return null;
  return candidates[0];
}
function visitCaptureDirectories(directory, callback) {
  if (!fs.existsSync(directory) || !fs.statSync(directory).isDirectory()) return;
  callback(directory);
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) if (entry.isDirectory()) visitCaptureDirectories(path.join(directory, entry.name), callback);
}
function buildProvenance(url, capture) {
  const files = listFiles(capture.directory).filter((file) => file !== capture.receiptPath).map((file) => ({ path: path.relative(capture.directory, file), bytes: fs.readFileSync(file) })).sort((left, right) => left.path.localeCompare(right.path));
  const content = Buffer.concat(files.flatMap((file) => [Buffer.from(`${file.path}\0`), file.bytes]));
  return { source_url_sha256: sha256(url), capture_receipt_sha256: sha256(fs.readFileSync(capture.receiptPath)), captured_content_sha256: sha256(content), basis: 'normalized source URL, capture-receipt.json bytes, and sorted retained capture files' };
}
function listFiles(directory) { const files = []; for (const entry of fs.readdirSync(directory, { withFileTypes: true })) { const item = path.join(directory, entry.name); if (entry.isDirectory()) files.push(...listFiles(item)); else if (entry.isFile()) files.push(item); } return files; }
function sha256(value) { return `sha256:${crypto.createHash('sha256').update(value).digest('hex')}`; }
export function validateMatrixEvidence(summary, fixtureId) {
  const readiness = summary?.matrix_evidence_readiness;
  if (readiness?.schema !== MATRIX_EVIDENCE_READINESS_SCHEMA || !Array.isArray(readiness.fixtures)) return { valid: false, reason: 'authoritative_output_missing' };
  const fixture = readiness.fixtures.find((row) => row.fixture_id === fixtureId);
  if (!fixture || fixture.readiness !== 'verified') return { valid: false, reason: 'runtime_evidence_incomplete' };
  return { valid: true, reason: null };
}
function matrixRuntimeFailure(result, output) {
  const timedOut = result?.status === null || result?.signal === 'SIGTERM' || result?.error?.code === 'ETIMEDOUT';
  return {
    stage: 'matrix',
    reason: timedOut ? 'command_timeout' : (result?.status === 0 ? 'authoritative_output_missing' : 'command_failed'),
    outcome: timedOut ? 'timed_out' : 'nonzero_or_missing_output',
    exit_status: result?.status ?? null,
    signal: result?.signal || null,
    output_file: output,
  };
}
function sourceSlug(url) { return new URL(url).hostname.replace(/[^a-z0-9.-]/gi, '-'); }
function shellCommand(step) { return [step.command, ...(step.args || [])].map((value) => /^[A-Za-z0-9_./:=@+-]+$/.test(value) ? value : `'${String(value).replaceAll("'", "'\\''")}'`).join(' '); }

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  const args = parseArgs(process.argv.slice(2));
  if (!args.url && process.argv[2] && !process.argv[2].startsWith('--')) args.url = process.argv[2];
  try { process.stdout.write(`${JSON.stringify(await runUrlLoopIntake(args), null, 2)}\n`); } catch (error) { process.stderr.write(`${error.message}\n`); process.exitCode = 1; }
}

function parseArgs(values) {
  const options = {};
  const booleanKeys = new Set(['runMatrix', 'allowExistingOutput', 'dryRun']);
  for (let index = 0; index < values.length; index += 1) {
    const value = values[index];
    if (!value.startsWith('--')) continue;
    const key = value.slice(2).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
    if (booleanKeys.has(key)) { options[key] = true; continue; }
    options[key] = values[++index];
  }
  return options;
}
