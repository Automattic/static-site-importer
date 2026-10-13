#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { resolveCaptureConfig, runUrlLoopIntake, runUrlLoopMatrix, validateCapture, validateMatrixEvidence } from './url-loop-intake.mjs';
import { resolveBlocksEnginePhpTransformerPath } from '../bench/static-site-fixture-matrix.bench.mjs';

export const CAPTURE_ARTIFACT_SCHEMA = 'static-site-importer/url-loop-capture/v1';
export const EVALUATION_ARTIFACT_SCHEMA = 'static-site-importer/url-loop-evaluation/v1';
export const WORKFLOW_SCHEMA = 'homeboy/workflow/v1';
export const WORKFLOW_ID = 'ssi-url-proof';
export const EVALUATION_EVIDENCE_ID = 'evaluation';
const MODULE_PATH = fileURLToPath(import.meta.url);
const SOURCE_VIEWS = [390, 768, 1440];
const TRANSFORMER_PACKAGE = 'automattic/blocks-engine-php-transformer';

// Snapshot explicit candidates before handing them to Codebox. The reference is
// the digest of the bytes Codebox will provision, not the independent CLI repo.
export function prepareTransformer(context, directory) {
  if (!context.transformer_path) {
    const lockBytes = fs.readFileSync(path.join(context.workspace, 'composer.lock'));
    const lock = JSON.parse(lockBytes);
    const compiler = lock.packages?.find((item) => item.name === TRANSFORMER_PACKAGE);
    const reference = compiler?.source?.reference || compiler?.dist?.reference;
    if (!compiler?.version || !/^[a-f0-9]{40,64}$/i.test(reference || '')) throw new Error('transformer_lock_identity_missing');
    return { transformer: { package: compiler.name, version: compiler.version, reference }, composer_lock_sha256: digest(lockBytes), path: '' };
  }
  const source = resolveBlocksEnginePhpTransformerPath(context.transformer_path);
  const snapshot = path.join(directory, 'php-transformer');
  fs.mkdirSync(directory, { recursive: true });
  fs.cpSync(source, snapshot, { recursive: true, dereference: true, errorOnExist: true, force: false, filter: (file) => !['.git', 'vendor'].includes(path.basename(file)) });
  const files = [];
  const visit = (root) => {
    for (const entry of fs.readdirSync(root, { withFileTypes: true }).sort((a, b) => a.name.localeCompare(b.name))) {
      const file = path.join(root, entry.name);
      if (entry.isDirectory()) visit(file);
      else if (entry.isFile()) files.push(Buffer.from(`${path.relative(snapshot, file)}\0`), fs.readFileSync(file));
    }
  };
  visit(snapshot);
  const reference = digest(Buffer.concat(files));
  const manifest = readJson(path.join(snapshot, 'composer.json'));
  if (manifest.name !== TRANSFORMER_PACKAGE) throw new Error('transformer_package_identity_mismatch');
  const transformer = { package: manifest.name, version: manifest.version || null, reference };
  // Exercise actual PHP autoload exports, rather than accepting filenames or
  // a package name as evidence of compatibility. Codebox owns dependency install.
  const proof = spawnSync('php', ['-r', String.raw`
    $root = realpath($argv[1]);
    $manifest = json_decode(file_get_contents($root . '/composer.json'), true);
    spl_autoload_register(static function ($class) use ($root, $manifest) {
      foreach ($manifest['autoload']['psr-4'] ?? [] as $prefix => $directories) {
        if (!str_starts_with($class, $prefix)) continue;
        foreach ((array) $directories as $directory) {
          $file = $root . '/' . $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
          if (is_file($file)) { require_once $file; return; }
        }
      }
    });
    $exports = [];
    foreach (['CssUrlRewriter' => 'rewrite', 'SrcsetParser' => 'parse'] as $name => $method) {
      $class = 'Automattic\\BlocksEngine\\PhpTransformer\\AssetAnalysis\\' . $name;
      $exports[$class] = class_exists($class) && is_callable([$class, $method])
        && str_starts_with((new ReflectionClass($class))->getFileName(), $root . '/');
    }
    echo json_encode($exports);
    exit(in_array(false, $exports, true) ? 1 : 0);
  `, snapshot], { encoding: 'utf8', timeout: 30000, maxBuffer: 1024 * 1024 });
  let exports = null;
  try { exports = JSON.parse(proof.stdout); } catch { /* Keep fatal/noisy PHP output as preparation evidence. */ }
  const evidence = { transformer, source, snapshot, required_exports: exports, exit_status: proof.status, error: proof.error?.message || proof.stderr || null, stdout: exports ? null : proof.stdout };
  writeJson(path.join(directory, 'transformer-preparation.json'), evidence);
  if (proof.status !== 0 || !exports || Object.values(exports).some((value) => value !== true)) {
    const error = new Error(`transformer_preparation_blocked:${proof.error?.code === 'ENOENT' ? 'php_unavailable' : 'required_exports_incompatible'}`);
    error.evidence = evidence;
    throw error;
  }
  return { transformer, path: snapshot, evidence };
}

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

// Homeboy starts every workflow step with an empty environment plus the names
// the definition declares, captured where the workflow is admitted. These are
// the public toolchain names capture and evaluation need; nothing else is inherited.
const RUNTIME_ENV = ['PATH', 'HOME', 'XDG_CACHE_HOME', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'PLAYWRIGHT_BROWSERS_PATH', 'PLAYWRIGHT_HOST_PLATFORM_OVERRIDE', 'NPM_CONFIG_CACHE', 'npm_config_cache'];

function boundedInteger(value, fallback, min, max, name) {
  const number = Number(value ?? fallback);
  if (!Number.isInteger(number) || number < min || number > max) throw new Error(`${name} must be an integer between ${min} and ${max}`);
  return number;
}

/**
 * One URL proof as a `homeboy/workflow/v1` definition: capture once, evaluate
 * the candidate, let the evaluation verdict gate a repair Cook, and re-check
 * until the verdict is `accepted` or the iteration/step budget stops it.
 */
export function buildUrlProofWorkflow(input) {
  const url = normalizeUrl(input.url);
  const blocksEngine = input.blocksEngine || process.env.SSI_BLOCKS_ENGINE_PATH;
  const wpCodeboxBin = input.wpCodeboxBin || process.env.HOMEBOY_WP_CODEBOX_BIN;
  if (!blocksEngine || !wpCodeboxBin) throw new Error('blocks-engine and wp-codebox-bin must be configured on this runner');
  const fixTaskUrl = input.fixTaskUrl || process.env.SSI_FIX_TASK_URL;
  if (!fixTaskUrl) throw new Error('fix-task-url must name the tracker the repair Cook works on');
  normalizeUrl(fixTaskUrl);
  const maxIterations = boundedInteger(input.maxIterations, 3, 1, 5, 'max-iterations');
  const maxStepRuns = boundedInteger(input.maxStepRuns, 12, 2, 30, 'max-step-runs');
  const deadline = String(input.deadline || '10h');
  if (!/^[1-9][0-9]*[smhd]$/.test(deadline)) throw new Error('deadline must be a duration such as 90m or 10h');
  const sourceId = sourceIdentity(url);
  if (input.instance && !/^[A-Za-z0-9_-]{1,32}$/.test(input.instance)) throw new Error('instance must be a short path-safe token');
  const loopId = input.instance ? `${sourceId}-${input.instance}` : sourceId;
  const workspace = path.resolve(input.workspace || process.cwd());
  const root = path.resolve(input.outputRoot || path.join(workspace, 'artifacts', 'url-loop', loopId));
  const node = path.resolve(input.nodeExecutable || process.execPath);
  const capture = resolveCaptureConfig(input);
  const runtimeToolchain = { node, env: Object.fromEntries(RUNTIME_ENV.filter((key) => key !== 'PATH' && process.env[key]).map((key) => [key, process.env[key]])) };
  const context = { source_id: sourceId, loop_id: loopId, root, workspace, blocks_engine: path.resolve(blocksEngine), wp_codebox_bin: path.resolve(wpCodeboxBin), transformer_path: input.transformerPath ? path.resolve(input.transformerPath) : '', capture, runtime_toolchain: runtimeToolchain, blocks_engine_sha: input.blocksEngineSha || null, wordpress_version: input.wordpressVersion || null };
  // The context is part of the pinned definition, so it is digested into the
  // instance identity and can never drift under a running instance.
  const command = (stage, args) => ({ program: node, args: [MODULE_PATH, stage, '--url', '{{inputs.url}}', '--context', JSON.stringify(context), ...args], cwd: workspace });
  const verify = [input.fixVerify || 'npm test'].flat();
  // Cook projects the evaluation report into the provider workspace as
  // declared evidence; the prompt names it by id, never by host path.
  const cook = {
    repo: input.fixRepo || 'static-site-importer',
    'task-url': fixTaskUrl,
    placement: input.fixPlacement || 'local',
    ...(input.fixBackend ? { backend: input.fixBackend } : {}),
    'provider-evidence': JSON.stringify({ id: EVALUATION_EVIDENCE_ID, source: '{{steps.evaluate.output/report_path}}' }),
    prompt: input.fixPrompt || `Repair the static-site-importer findings for {{inputs.url}} at candidate {{inputs.candidate_sha}}. The \`${EVALUATION_EVIDENCE_ID}\` provider evidence is the evaluation report; its findings and missing evidence are the work list (workflow {{instance}}, iteration {{iteration}}).`,
    verify,
  };
  const workflow = {
    schema: WORKFLOW_SCHEMA,
    id: WORKFLOW_ID,
    inputs: { url: { required: true }, candidate_sha: { required: true } },
    env: { public: RUNTIME_ENV.filter((name) => process.env[name]) },
    budget: { max_step_runs: maxStepRuns, deadline },
    iterate: { until: { step: 'evaluate', output: '/verdict', equals: 'accepted' }, max_iterations: maxIterations },
    steps: [
      { id: 'capture', timeout: '15m', when: { not: { step: 'capture', outcome: ['succeeded'] } }, exec: command('capture', []) },
      { id: 'evaluate', needs: ['capture'], timeout: '90m', exec: command('evaluate', ['--capture', '{{steps.capture.output}}', '--candidate-sha', '{{inputs.candidate_sha}}', '--action', '{{instance}}-i{{iteration}}']) },
      { id: 'fix', needs: ['evaluate'], when: { step: 'evaluate', output: '/verdict', equals: 'findings' }, retry: { max: 1, on: ['failed'] }, cook },
    ],
  };
  return { workflow, context, inputs: { url } };
}

function requireContext(context, url) {
  if (!context?.root || !context.workspace || context.source_id !== sourceIdentity(url)) throw new Error('workflow_context_url_mismatch');
  return context;
}

function captureArtifact(handoff, capture) {
  if (handoff.status !== 'needs_evaluation' || !handoff.fixture?.fixture_root || !validateCapture(handoff.capture_receipt, { schema: capture.receipt_schema }).valid || handoff.capture_receipt?.source?.url !== handoff.url || !handoff.provenance?.captured_content_sha256) {
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
    producer: handoff.identities.capture,
  };
}

// A URL proof captures its source once. Later instances for the same proof
// root (new candidate SHAs) evaluate that same retained capture; a retained
// blocked capture stays blocked rather than being silently replaced.
export async function runCapture(context, url, dependencies = {}) {
  const normalized = normalizeUrl(url);
  requireContext(context, normalized);
  const outputRoot = path.join(context.root, 'capture-intake');
  const retained = path.join(outputRoot, 'url-loop-handoff.json');
  const handoff = fs.existsSync(retained)
    ? { ...readJson(retained), handoff_path: retained }
    : await (dependencies.capture || runUrlLoopIntake)({ url: normalized, outputRoot, capture: context.capture, runtimeToolchain: context.runtime_toolchain });
  const artifact = captureArtifact(handoff, context.capture);
  if (artifact.url !== normalized) throw new Error('capture_url_mismatch');
  return artifact;
}

export function evaluateMatrixSummary({ summary, benchStatus, capture, candidateSha, runId }) {
  const readiness = summary?.matrix_evidence_readiness;
  const row = readiness?.fixtures?.find((item) => item.fixture_id === capture.fixture_id);
  const evidence = validateMatrixEvidence(summary, capture.fixture_id);
  const observedViewports = summary?.surface_coverage?.viewports || [];
  const missing = [...new Set([...(row?.missing || []), ...(evidence.valid ? [] : [evidence.reason]), 'solved_site_promotion_receipt', ...SOURCE_VIEWS.filter((width) => !observedViewports.includes(width)).map((width) => `visual_viewport_${width}_missing`)])];
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

/**
 * The workflow gate reads `verdict`: `accepted` only for a solved site,
 * `findings` when the matrix reports repairable failures, otherwise the
 * evaluation status (`needs_evidence`, `needs_review`, `blocked`).
 */
export function evaluationVerdict(evaluation, reportPath) {
  return {
    verdict: evaluation.acceptance?.solved === true ? 'accepted' : evaluation.status === 'needs_repair' ? 'findings' : evaluation.status,
    status: evaluation.status,
    report_path: reportPath,
    candidate_sha: evaluation.candidate_sha,
    run_id: evaluation.run_id,
    fixture_id: evaluation.fixture_id,
    findings: evaluation.findings.length,
    missing: evaluation.missing,
    blocker: evaluation.preparation_blocker?.reason || null,
  };
}

export async function runEvaluation({ context, url, capture, candidateSha: requestedSha, actionId }, dependencies = {}) {
  const normalized = normalizeUrl(url);
  requireContext(context, normalized);
  if (capture?.schema !== CAPTURE_ARTIFACT_SCHEMA || capture.url !== normalized || !fs.existsSync(capture.handoff_path)) throw new Error('capture_handoff_missing');
  const handoff = readJson(capture.handoff_path);
  if (handoff.provenance?.captured_content_sha256 !== capture.provenance.captured_content_sha256 || handoff.provenance?.source_url_sha256 !== capture.provenance.source_url_sha256 || handoff.fixture?.fixture_root !== capture.fixture_root) throw new Error('capture_handoff_identity_mismatch');
  const candidateSha = requiredCommit(requestedSha || (dependencies.commit || gitCommit)(context.workspace), 'candidate_sha');
  const observedCommit = (dependencies.observedCommit || gitCommitIfAvailable)(context.workspace);
  if (observedCommit && observedCommit !== candidateSha) throw new Error('candidate_workspace_commit_mismatch');
  if (!/^[A-Za-z0-9_-]{1,96}$/.test(String(actionId || ''))) throw new Error('evaluation_action_identity_invalid');
  const runId = `${context.loop_id}-matrix-${candidateSha.slice(0, 12)}-${actionId}`;
  const directory = path.join(context.root, 'evaluations', candidateSha, actionId);
  const output = path.join(directory, 'homeboy-bench-result.json');
  const reportPath = path.join(directory, 'evaluation.json');
  if (fs.existsSync(directory)) throw new Error('matrix_output_not_fresh');
  let compiler;
  try { compiler = prepareTransformer(context, directory); }
  catch (error) {
    const evaluation = evaluateMatrixSummary({ summary: null, capture, candidateSha, runId });
    evaluation.preparation_blocker = { stage: 'preparation', reason: 'transformer_dependency_incompatible', message: error.message, evidence: error.evidence || { source: context.transformer_path || path.join(context.workspace, 'composer.lock'), package: TRANSFORMER_PACKAGE } };
    writeJson(reportPath, evaluation);
    return { evaluation, report_path: reportPath };
  }
  const coverage = Math.min(10, capture.routes - 1);
  const evaluated = await runUrlLoopMatrix({
    fixtureRoot: capture.fixture_root, fixtureId: capture.fixture_id,
    staticSiteImporter: context.workspace, blocksEngine: context.blocks_engine, output, cwd: context.workspace,
    matrixOptions: { runId, local: true, surfaceCoverage: coverage, mode: compiler.path ? 'development-override' : 'release-proof', blocksEnginePhpTransformerPath: compiler.path, ...(compiler.path ? { blocksEnginePhpTransformerReference: compiler.transformer.reference } : {}) },
    matrixArgs: [...(compiler.path ? ['--blocks-engine-php-transformer-path', compiler.path, '--blocks-engine-php-transformer-reference', compiler.transformer.reference] : ['--mode', 'release-proof']), '--run-id', runId, '--wp-codebox-bin', context.wp_codebox_bin, '--surface-coverage', String(coverage), '--local', '--skip-install', '--skip-sync'],
  }, dependencies);
  const { summary, result } = evaluated;
  const evaluation = evaluateMatrixSummary({ summary, benchStatus: result.status, capture, candidateSha, runId });
  evaluation.transformer = compiler.transformer;
  if (compiler.composer_lock_sha256) evaluation.composer_lock_sha256 = compiler.composer_lock_sha256;
  if (compiler.evidence) evaluation.transformer_preparation = compiler.evidence;
  evaluation.versions = { capture: capture.producer, ssi: candidateSha, blocks_engine: context.blocks_engine_sha || null, wordpress: context.wordpress_version || null, browser: summary?.lane_identity?.browser || null };
  if (!observedCommit) evaluation.missing.push('candidate_commit_unverified');
  evaluation.command = evaluated.commands[0];
  writeJson(reportPath, evaluation);
  if (evaluation.status === 'blocked') throw new Error(`matrix_runtime_blocked:${result.status ?? 'signal'}`);
  return { evaluation, report_path: reportPath };
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

const REPEATABLE = new Set(['fixVerify']);
export function parseArgs(argv) {
  const options = {};
  for (let i = 0; i < argv.length; i += 1) {
    if (!argv[i].startsWith('--')) continue;
    const key = argv[i].slice(2).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase());
    const value = argv[++i];
    options[key] = REPEATABLE.has(key) ? [...(options[key] || []), value] : value;
  }
  return options;
}

function writeStepOutput(value) {
  if (process.env.HOMEBOY_STEP_OUTPUT) writeJson(process.env.HOMEBOY_STEP_OUTPUT, value);
  process.stdout.write(`${JSON.stringify(value)}\n`);
}

async function main() {
  const [operation, ...argv] = process.argv.slice(2);
  const options = parseArgs(argv);
  if (operation === 'capture') {
    writeStepOutput(await runCapture(JSON.parse(options.context), options.url));
    return;
  }
  if (operation === 'evaluate') {
    const { evaluation, report_path: reportPath } = await runEvaluation({ context: JSON.parse(options.context), url: options.url, capture: JSON.parse(options.capture), candidateSha: options.candidateSha, actionId: options.action });
    const verdict = evaluationVerdict(evaluation, reportPath);
    writeStepOutput(verdict);
    // A typed blocker fails the step, and with it the workflow instance.
    if (verdict.verdict === 'blocked') throw new Error(`evaluation_blocked:${verdict.blocker || 'matrix_evidence_missing'}`);
    return;
  }
  if (operation === 'workflow') { process.stdout.write(`${JSON.stringify(buildUrlProofWorkflow(options).workflow, null, 2)}\n`); return; }
  if (operation === 'start') {
    const { workflow, context, inputs } = buildUrlProofWorkflow(options);
    const candidateSha = requiredCommit(options.candidateSha || process.env.SSI_CANDIDATE_SHA || gitCommit(context.workspace), 'candidate_sha');
    if (gitCommitIfAvailable(context.workspace)) verifyCandidateWorkspace(context.workspace, candidateSha);
    const file = path.join(context.root, 'workflow.json');
    writeJson(file, workflow);
    const result = spawnSync(options.homeboyBin || 'homeboy', ['workflow', 'run', file, '--input', `url=${inputs.url}`, '--input', `candidate_sha=${candidateSha}`], { cwd: context.workspace, stdio: ['ignore', 'pipe', 'inherit'], encoding: 'utf8' });
    if (result.status !== 0) { process.stdout.write(result.stdout || ''); throw new Error(`workflow_admission_failed:${result.status}`); }
    const instance = JSON.parse(result.stdout).data?.instance;
    process.stdout.write(`${JSON.stringify({ instance, loop_id: context.loop_id, workflow_file: file, status_command: `homeboy workflow status ${instance}` })}\n`);
    return;
  }
  throw new Error('usage: url-loop-controller.mjs start|workflow --url <url> --capture-command <JSON argv> --fix-task-url <url> [--capture-receipt-schema <id>] [--blocks-engine <path>] [--wp-codebox-bin <path>] [--candidate-sha <sha>] [--output-root <path>] [--instance <token>] [--max-iterations 3] [--max-step-runs 12] [--deadline 10h] [--fix-repo <repo>] [--fix-verify <command>]... [--fix-placement local] [--fix-backend <backend>] [--fix-prompt <template>]');
}

if (process.argv[1] === MODULE_PATH) main().catch((error) => { process.stderr.write(`${error.message}\n`); process.exitCode = 1; });
