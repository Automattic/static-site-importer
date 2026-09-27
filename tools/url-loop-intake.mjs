#!/usr/bin/env node

import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { materializeGeneratedArtifactFixtures, discoverGeneratedArtifacts } from '../lib/artifact-intake.mjs';

export const URL_LOOP_INTAKE_SCHEMA = 'static-site-importer/url-loop-intake/v1';
export const CAPTURE_RECEIPT_SCHEMA = 'data-liberation-agent/capture-receipt/v1';

export function validateCapture(input = {}) {
  const receipt = input.receipt || input;
  const failures = [];
  if (receipt.schema !== CAPTURE_RECEIPT_SCHEMA) failures.push('capture_receipt_schema_unknown');
  if (receipt.status !== 'completed' || receipt.complete !== true) failures.push('capture_incomplete');
  if (!receipt.source_url) failures.push('capture_source_url_missing');
  if (!receipt.source_digest || !/^sha256:[a-f0-9]{64}$/i.test(receipt.source_digest)) failures.push('source_digest_missing');
  if (!known(receipt.dla?.version) || !known(receipt.dla?.commit)) failures.push('dla_provenance_missing');
  if (!Number.isInteger(receipt.file_count) || receipt.file_count < 1) failures.push('capture_files_missing');
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
  const handoff = {
    schema: URL_LOOP_INTAKE_SCHEMA,
    status: input.failures?.length ? 'blocked' : (input.matrix?.status || 'captured'),
    url: input.url || '',
    source_digest: input.sourceDigest || '',
    capture_receipt: input.captureReceipt || null,
    identities: input.identities || {},
    fixture: input.fixture || null,
    matrix: input.matrix || null,
    finding_packet_refs: input.findingPacketRefs || [],
    failures: input.failures || [],
    commands: input.commands || [],
    acceptance: {
      solved: false,
      reason: 'url_loop_intake_does_not certify solved-site acceptance',
    },
  };
  return handoff;
}

export async function runUrlLoopIntake(input = {}, dependencies = {}) {
  const url = normalizeUrl(input.url);
  const configInput = typeof input.dlaConfig === 'string' ? readJson(path.resolve(input.dlaConfig)) : (input.dlaConfig || input);
  const config = normalizeDlaConfig(configInput || {});
  const outputRoot = path.resolve(input.outputRoot || path.join(process.cwd(), 'artifacts', sourceSlug(url)));
  const captureRoot = path.join(outputRoot, 'capture');
  const artifactRoot = path.join(captureRoot, 'artifacts');
  const receiptPath = path.join(captureRoot, 'capture-receipt.json');
  const fixtureRoot = path.join(outputRoot, 'fixtures', 'websites');
  const handoffPath = path.resolve(input.handoff || path.join(outputRoot, 'url-loop-handoff.json'));
  const commands = [];
  const failures = [];
  fs.mkdirSync(outputRoot, { recursive: true });
  if (fs.readdirSync(outputRoot).length > 0 && !input.allowExistingOutput) failures.push('output_root_not_fresh');
  if (!failures.length) {
    fs.mkdirSync(artifactRoot, { recursive: true });
    const captureArgs = config.captureArgs.map((arg) => String(arg).replaceAll('{url}', url).replaceAll('{output}', artifactRoot).replaceAll('{receipt}', receiptPath));
    const command = { command: config.cli, args: captureArgs };
    commands.push({ stage: 'capture', ...command, shell: shellCommand(command) });
    const result = (dependencies.spawn || spawnSync)(config.cli, captureArgs, { stdio: 'inherit' });
    if ((result.status ?? 1) !== 0) failures.push(`capture_command_failed:${result.status ?? 1}`);
  }

  let captureReceipt = readJson(receiptPath);
  const captureValidation = validateCapture(captureReceipt || {});
  failures.push(...captureValidation.failures);
  if (captureReceipt && captureReceipt.source_url !== url) failures.push('capture_source_url_mismatch');
  let fixture;
  if (!failures.length) {
    try {
      validateArtifactSelection(artifactRoot);
      const intake = materializeGeneratedArtifactFixtures({ artifactRoot, fixtureRoot });
      if (intake.count !== 1) throw new Error(`fixture_count_invalid:${intake.count}`);
      fixture = intake.fixtures[0];
    } catch (error) {
      failures.push(error.message);
    }
  }

  let matrix = null;
  if (!failures.length && input.runMatrix) {
    try {
      // Keep capture/intake diagnostics usable without the optional visual-matrix
      // dependencies. The canonical matrix module is loaded only when requested.
      const { buildFixtureMatrixRunPlan, summarizeRun } = await import('./run-fixture-matrix.mjs');
      const matrixInput = { ...input.matrix, fixtureRoot, targetFixture: fixture.id, staticSiteImporter: input.staticSiteImporter, blocksEngine: input.blocksEngine, output: path.join(outputRoot, 'matrix', 'homeboy-bench-result.json') };
      const plan = buildFixtureMatrixRunPlan(matrixInput);
      matrix = { status: 'planned', plan, command: plan.steps.at(-1)?.retry_command || '' };
      if (!input.dryRun) {
        const result = (dependencies.spawn || spawnSync)(process.execPath, [fileURLToPath(import.meta.url).replace('url-loop-intake.mjs', 'run-fixture-matrix.mjs'), '--static-site-importer', input.staticSiteImporter, '--fixture-root', fixtureRoot, '--target-fixture', fixture.id, '--output', matrixInput.output, ...(input.matrixArgs || [])], { stdio: 'inherit' });
        matrix.status = result.status === 0 ? 'passed' : 'failed';
        if (result.status !== 0) failures.push(`matrix_command_failed:${result.status}`);
        if (fs.existsSync(matrixInput.output)) matrix.summary = summarizeRun(plan, { status: matrix.status });
      }
      commands.push({ stage: 'matrix', command: process.execPath, args: ['tools/run-fixture-matrix.mjs', '--static-site-importer', input.staticSiteImporter, '--fixture-root', fixtureRoot, '--target-fixture', fixture.id, '--output', matrixInput.output, ...(input.matrixArgs || [])] });
    } catch (error) {
      failures.push(`matrix_setup_failed:${error.message}`);
    }
  }
  const handoff = createHandoff({ url, sourceDigest: captureReceipt?.source_digest, captureReceipt, fixture, matrix, failures, commands, findingPacketRefs: matrix?.summary?.finding_packet_refs || [], identities: { dla: config.identity, ssi: input.ssiIdentity, blocks_engine: input.blocksEngineIdentity, wordpress: input.wordpressIdentity } });
  fs.mkdirSync(path.dirname(handoffPath), { recursive: true });
  fs.writeFileSync(handoffPath, `${JSON.stringify(handoff, null, 2)}\n`);
  return { ...handoff, handoff_path: handoffPath };
}

function normalizeUrl(value) { const url = new URL(String(value || '')); if (!['http:', 'https:'].includes(url.protocol)) throw new Error('url must be a public http(s) URL'); return url.href; }
function normalizeDlaConfig(input) {
  const cli = input.dlaCli || input.cli;
  if (!cli || !known(input.dlaVersion) || !known(input.dlaCommit)) throw new Error('explicit, known DLA CLI, version, and commit are required');
  return { cli, captureArgs: input.captureArgs || ['capture', '--url', '{url}', '--output', '{output}', '--receipt', '{receipt}'], identity: { cli, version: input.dlaVersion, commit: input.dlaCommit } };
}
function known(value) { return Boolean(value && !/^(?:unknown|latest|dev|dirty)$/i.test(String(value).trim())); }
function readJson(file) { try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { return null; } }
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
