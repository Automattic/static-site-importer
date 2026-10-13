#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import net from 'node:net';
import path from 'node:path';
import { spawn, spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';

export const EVALUATION_ARTIFACT_SCHEMA = 'static-site-importer/url-loop-evaluation/v2';
export const REPAIR_EVIDENCE_SCHEMA = 'static-site-importer/url-loop-repair-evidence/v1';
export const WORKFLOW_SCHEMA = 'homeboy/workflow/v1';
export const WORKFLOW_ID = 'ssi-url-proof';
const MODULE_PATH = fileURLToPath(import.meta.url);
const STAGE_MOUNT = '/url-loop';
const LOSS_COUNTS = ['unsupported_fallback_count', 'core_html_block_count', 'freeform_block_count', 'invalid_block_count'];

/** Repositories the loop always composes and repairs. */
export const FIXED_REPOSITORIES = {
  'static-site-importer': { role: 'importer', step: 'fix-ssi', option: 'ssi', base: 'main', remote: 'https://github.com/Automattic/static-site-importer.git', verify: ['npm test'] },
  'blocks-engine': { role: 'compiler', step: 'fix-blocks-engine', option: 'blocksEngine', base: 'trunk', remote: 'https://github.com/Automattic/blocks-engine.git', verify: ['composer --working-dir=php-transformer test'] },
};
export const CAPTURE_PRODUCER_ENV = 'SSI_URL_LOOP_CAPTURE_PRODUCER';
const CAPTURE_RECEIPT_FILE = 'capture-receipt.json';
const SLUG = /^[a-z0-9][a-z0-9._-]{0,99}$/;

function argvList(value, name, placeholders = []) {
  if (!Array.isArray(value) || !value.length || value.some((arg) => typeof arg !== 'string' || arg === '')) throw new Error(`capture_producer_invalid: ${name} must be a non-empty JSON array of non-empty strings`);
  for (const placeholder of placeholders) if (!value.some((arg) => arg.includes(placeholder))) throw new Error(`capture_producer_invalid: ${name} must contain ${placeholder}`);
  return [...value];
}

/**
 * The capture producer is operator configuration, never harness code: the
 * repository the loop composes and repairs for capture, and the commands that
 * build it, capture a URL and compare a capture. Placeholders: `{checkout}`
 * (the composed checkout), `{url}`, `{output}`, `{capture}` (the directory
 * holding the Blocks Engine capture receipt), `{stage}` (`capture` or
 * `materialization`) and `{candidate}` (the served WordPress origin).
 */
export function resolveCaptureProducer(input = {}, env = process.env) {
  let producer = input.captureProducer ?? env[CAPTURE_PRODUCER_ENV];
  if (!producer) throw new Error(`capture_producer_missing: pass --capture-producer '<JSON>' or set ${CAPTURE_PRODUCER_ENV}`);
  if (typeof producer === 'string') {
    try { producer = JSON.parse(producer); } catch { throw new Error('capture_producer_invalid: expected a JSON object'); }
  }
  if (!SLUG.test(String(producer.repository || '')) || FIXED_REPOSITORIES[producer.repository]) throw new Error('capture_producer_invalid: repository must name the capture repository');
  if (!producer.checkout || !producer.remote || !producer.base) throw new Error('capture_producer_invalid: checkout, remote and base are required');
  let failureDetails;
  if (producer.failure_details !== undefined) {
    const value = producer.failure_details;
    if (!value || typeof value !== 'object' || Array.isArray(value) || typeof value.file !== 'string' || !value.file.includes('{capture}') || typeof value.pointer !== 'string' || !value.pointer.startsWith('/') || value.pointer === '/') throw new Error('capture_producer_invalid: failure_details must contain file using {capture} and a JSON pointer');
    failureDetails = { file: value.file, pointer: value.pointer };
  }
  return {
    repository: producer.repository,
    checkout: path.resolve(producer.checkout),
    remote: String(producer.remote),
    base: String(producer.base),
    build: (producer.build || []).map((command, index) => argvList(command, `build[${index}]`)),
    capture: argvList(producer.capture, 'capture', ['{url}', '{output}']),
    compare: argvList(producer.compare, 'compare', ['{capture}', '{stage}']),
    compare_candidate: argvList(producer.compare_candidate, 'compare_candidate', ['{candidate}']),
    compare_report: String(producer.compare_report || '{capture}/compare/{stage}/report.json'),
    ...(failureDetails ? { failure_details: failureDetails } : {}),
    verify: [producer.verify || 'npm test'].flat(),
    ...(producer.task_url ? { task_url: String(producer.task_url) } : {}),
  };
}

/** Substitute `{name}` placeholders in an argv array or template string. */
export function expandTemplate(template, values) {
  const expand = (value) => value.replace(/\{([a-z_]+)\}/g, (match, key) => (Object.hasOwn(values, key) ? String(values[key]) : match));
  return Array.isArray(template) ? template.map(expand) : expand(template);
}

function digest(value) { return crypto.createHash('sha256').update(value).digest('hex'); }
function normalizeUrl(value) {
  const url = new URL(String(value || ''));
  if (!['https:', 'http:'].includes(url.protocol)) throw new Error('url must use http(s)');
  url.hash = '';
  return url.href;
}
function readJson(file) { return JSON.parse(fs.readFileSync(file, 'utf8')); }
function readJsonIfPresent(file) { return fs.existsSync(file) ? readJson(file) : null; }
function writeJson(file, value) { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`); }

/** A typed blocker: the stage could not produce evidence. It fails the evaluate step. */
export class LoopBlocker extends Error {
  constructor(reason, detail = null) { super(reason); this.reason = reason; this.detail = detail; }
}

export function sourceIdentity(url) {
  return `ssi-url-${digest(normalizeUrl(url)).slice(0, 20)}`;
}

/** The one place repair branch names are defined: Cook `head` and candidate discovery both use it. */
export function repairBranch(instance, repo, iteration) {
  if (!SLUG.test(String(repo))) throw new Error(`repair_repository_invalid:${repo}`);
  return `url-loop/${instance}/${repo}-i${iteration}`;
}

/** Order this instance's discovered repair refs for one repository by iteration. */
export function orderRepairBranches(refs, instance, repo) {
  const prefix = repairBranch(instance, repo, '');
  return refs
    .map(({ branch, sha }) => ({ branch, sha, iteration: branch.startsWith(prefix) ? Number(branch.slice(prefix.length)) : NaN }))
    .filter((ref) => Number.isInteger(ref.iteration) && ref.iteration > 0)
    .sort((a, b) => a.iteration - b.iteration);
}

// Homeboy starts every workflow step with an empty environment plus the names
// the definition declares, captured where the workflow is admitted. These are
// the public toolchain names the evaluation needs; nothing else is inherited.
const RUNTIME_ENV = ['PATH', 'HOME', 'TMPDIR', 'XDG_CACHE_HOME', 'XDG_CONFIG_HOME', 'XDG_DATA_HOME', 'PLAYWRIGHT_BROWSERS_PATH', 'PLAYWRIGHT_HOST_PLATFORM_OVERRIDE', 'NPM_CONFIG_CACHE', 'npm_config_cache', 'COMPOSER_HOME'];

function boundedInteger(value, fallback, min, max, name) {
  const number = Number(value ?? fallback);
  if (!Number.isInteger(number) || number < min || number > max) throw new Error(`${name} must be an integer between ${min} and ${max}`);
  return number;
}

/**
 * One URL as a `homeboy/workflow/v1` repair loop: every iteration evaluates a
 * candidate composed from the importer, compiler and configured capture
 * repository tips plus this instance's repair branches, and each repository
 * with routed findings gets a repair Cook. The loop stops when the verdict is
 * `accepted` or a budget ends it.
 */
export function buildUrlProofWorkflow(input) {
  const url = normalizeUrl(input.url);
  const workspace = path.resolve(input.workspace || process.cwd());
  const blocksEngine = input.blocksEngine || process.env.SSI_BLOCKS_ENGINE_PATH;
  const wpCodeboxBin = input.wpCodeboxBin || process.env.HOMEBOY_WP_CODEBOX_BIN;
  if (!blocksEngine || !wpCodeboxBin) throw new Error('blocks-engine and wp-codebox-bin must be configured on this runner');
  const producer = resolveCaptureProducer(input);
  // Every repair Cook works on a tracker the operator names: `--task-url` for
  // the loop, overridable per repository.
  const taskUrl = (value) => {
    const tracker = value || input.taskUrl || process.env.SSI_URL_LOOP_TASK_URL;
    if (!tracker) throw new Error('task-url must name the tracker the repair Cooks work on');
    return normalizeUrl(tracker);
  };
  const maxIterations = boundedInteger(input.maxIterations, 3, 1, 5, 'max-iterations');
  const maxStepRuns = boundedInteger(input.maxStepRuns, 20, 4, 30, 'max-step-runs');
  const deadline = String(input.deadline || '10h');
  if (!/^[1-9][0-9]*[smhd]$/.test(deadline)) throw new Error('deadline must be a duration such as 90m or 10h');
  const sourceId = sourceIdentity(url);
  if (input.instance && !/^[A-Za-z0-9_-]{1,32}$/.test(input.instance)) throw new Error('instance must be a short path-safe token');
  const loopId = input.instance ? `${sourceId}-${input.instance}` : sourceId;
  const root = path.resolve(input.outputRoot || path.join(workspace, 'artifacts', 'url-loop', loopId));
  const node = path.resolve(input.nodeExecutable || process.execPath);
  const fixed = (repo, checkout) => ({ role: FIXED_REPOSITORIES[repo].role, path: path.resolve(checkout), remote: FIXED_REPOSITORIES[repo].remote, base: FIXED_REPOSITORIES[repo].base });
  const { checkout, remote, base, repository } = producer;
  const commands = { build: producer.build, capture: producer.capture, compare: producer.compare, compare_candidate: producer.compare_candidate, compare_report: producer.compare_report };
  const context = {
    source_id: sourceId,
    loop_id: loopId,
    root,
    workspace,
    repositories: {
      'static-site-importer': fixed('static-site-importer', workspace),
      'blocks-engine': fixed('blocks-engine', blocksEngine),
      [repository]: { role: 'capture', path: checkout, remote, base },
    },
    capture_producer: { repository, ...commands },
    wp_codebox_bin: path.resolve(wpCodeboxBin),
    wordpress_version: String(input.wordpressVersion || '7.1'),
    import_steps: boundedInteger(input.importSteps, 24, 1, 200, 'import-steps'),
    runtime_toolchain: { node, env: Object.fromEntries(RUNTIME_ENV.filter((key) => key !== 'PATH' && process.env[key]).map((key) => [key, process.env[key]])) },
  };
  // The context is part of the pinned definition, so it is digested into the
  // instance identity and can never drift under a running instance.
  const evaluate = { program: node, args: [MODULE_PATH, 'evaluate', '--url', '{{inputs.url}}', '--instance', '{{instance}}', '--iteration', '{{iteration}}', '--context', JSON.stringify(context)], cwd: workspace };
  const cook = (repo, tracker, verify) => ({
    repo,
    'task-url': taskUrl(tracker),
    head: repairBranch('{{instance}}', repo, '{{iteration}}'),
    placement: input.fixPlacement || 'local',
    ...(input.fixBackend ? { backend: input.fixBackend } : {}),
    'provider-evidence': JSON.stringify({ id: `${repo}-evaluation`, source: `{{steps.evaluate.output/repairs/${repo}/report_path}}` }),
    prompt: `Repair the ${repo} findings for {{inputs.url}} (URL repair loop {{instance}}, iteration {{iteration}}). The ${repo}-evaluation provider evidence is the scoped work list with the composed candidate SHAs and stage reports. Fix the cause generically in ${repo}; the next iteration measures this branch.`,
    verify,
    'draft-pr': false,
  });
  const repairSteps = [
    ...Object.entries(FIXED_REPOSITORIES).map(([repo, settings]) => ({ id: settings.step, repo, cook: cook(repo, input[`${settings.option}TaskUrl`], [input[`${settings.option}Verify`] || settings.verify].flat()) })),
    { id: 'fix-capture', repo: repository, cook: cook(repository, producer.task_url, producer.verify) },
  ];
  const workflow = {
    schema: WORKFLOW_SCHEMA,
    id: WORKFLOW_ID,
    inputs: { url: { required: true } },
    env: { public: RUNTIME_ENV.filter((name) => process.env[name]) },
    budget: { max_step_runs: maxStepRuns, deadline },
    iterate: { until: { step: 'evaluate', output: '/verdict', equals: 'accepted' }, max_iterations: maxIterations },
    steps: [
      { id: 'evaluate', timeout: '90m', exec: evaluate },
      ...repairSteps.map(({ id, repo, cook: definition }) => ({ id, needs: ['evaluate'], when: { step: 'evaluate', output: `/repairs/${repo}`, exists: true }, retry: { max: 1, on: ['failed'] }, cook: definition })),
    ],
  };
  return { workflow, context, inputs: { url } };
}

/** Route evidence to exactly the repository that owns the repair. */
export function routeFindings({ captureRepository, captureFailures = [], captureCompare, packets = [], importFailures = [], materializationCompare } = {}) {
  const repairs = {};
  const add = (repo, item) => { (repairs[repo] ||= { findings: [], report_path: null }).findings.push(item); };
  for (const failure of captureFailures) add(captureRepository, { kind: 'capture_failure', ...failure });
  if (captureCompare?.passed === false) add(captureRepository, { kind: 'capture_compare_failed', ...captureCompare });
  for (const packet of packets) add(String(packet.owner || '').startsWith('blocks-engine/') ? 'blocks-engine' : 'static-site-importer', { kind: 'finding_packet', ...packet });
  for (const failure of importFailures) add('static-site-importer', { kind: 'import_failure', ...failure });
  if (materializationCompare?.passed === false) add('static-site-importer', { kind: 'materialization_compare_failed', ...materializationCompare });
  return repairs;
}

/**
 * The loop's stop rule. `accepted` needs passing capture and materialization
 * comparisons, `quality_pass`, and zero fallback/HTML/freeform/invalid blocks.
 * Failing evidence with routed work is `findings`; anything else is `blocked`.
 * This is the loop stop condition only: SSI's solved-site promotion receipt
 * remains a separate publication step.
 */
export function evaluateLocalEvidence(evidence) {
  const missing = evidence.capture_compare == null || evidence.materialization_compare == null || evidence.quality == null;
  const quality = evidence.quality || {};
  const lossCount = LOSS_COUNTS.reduce((sum, key) => sum + Number(quality[key] || 0), 0);
  const failed = evidence.capture_compare?.passed !== true || evidence.materialization_compare?.passed !== true || quality.quality_pass !== true || lossCount !== 0;
  const routed = routeFindings({ captureRepository: evidence.capture_repository, captureFailures: evidence.capture_failures, captureCompare: evidence.capture_compare, packets: evidence.finding_packets, importFailures: evidence.import_failures, materializationCompare: evidence.materialization_compare });
  const verdict = missing ? 'blocked' : !failed ? 'accepted' : Object.keys(routed).length ? 'findings' : 'blocked';
  return {
    verdict,
    // An accepted candidate launches no repair, even for advisory evidence.
    repairs: verdict === 'findings' ? routed : {},
    loss_count: lossCount,
    blocker: missing ? 'evaluation_evidence_missing' : verdict === 'blocked' ? 'unrouted_failure' : null,
    acceptance: { solved: verdict === 'accepted', scope: 'loop_stop_condition', note: 'The solved-site promotion receipt remains a separate publication step.' },
  };
}

/** Write one evidence file per routed repository and point its report_path at it. */
export function writeRepairEvidence(directory, repairs, common) {
  for (const [repo, work] of Object.entries(repairs)) {
    const file = path.join(directory, 'repairs', `${repo}.json`);
    writeJson(file, { schema: REPAIR_EVIDENCE_SCHEMA, repository: repo, ...common, findings: work.findings });
    work.report_path = file;
  }
  return repairs;
}

export function importRequest(url) {
  const slug = `url-loop-${new URL(url).hostname.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}`.slice(0, 60);
  return { operation: 'apply', source: { type: 'files', ref: 'request-bundle:website', entrypoint: 'index.html' }, slug, name: new URL(url).hostname, site_title: new URL(url).hostname, activate: true, overwrite: true, fail_on_quality: false };
}

/**
 * WP Codebox recipe for one import. PHP.wasm has no subprocesses, so SSI is
 * driven through its externally stepped `--single-step --state` mode: repeated
 * identical steps converge and replay the terminal result. Playground needs
 * WP-CLI declared, and Jetpack preinstalled because Playground WP-CLI cannot
 * download the form provider SSI requires.
 */
export function codeboxRecipe({ pluginZip, stage, wordpressVersion, importSteps }) {
  const command = `static-site-importer import --single-step --state=${STAGE_MOUNT}/state.json --request=${STAGE_MOUNT}/request.json --report=${STAGE_MOUNT}/import-report.json`;
  return {
    schema: 'wp-codebox/workspace-recipe/v1',
    runtime: {
      wp: wordpressVersion,
      blueprint: {
        extraLibraries: ['wp-cli'],
        features: { networking: true },
        steps: [{ step: 'installPlugin', pluginData: { resource: 'wordpress.org/plugins', slug: 'jetpack' }, options: { activate: false } }],
      },
    },
    inputs: {
      extra_plugins: [{ source: pluginZip, slug: 'static-site-importer', activate: true }],
      mounts: [{ type: 'directory', source: stage, target: STAGE_MOUNT, mode: 'readwrite' }],
    },
    workflow: { steps: Array.from({ length: importSteps }, () => ({ command: 'wordpress.invoke-wp-cli', args: [`command=${command}`, 'mutates=true'] })) },
  };
}

export function codeboxArgs({ recipe, artifacts, port, timeoutMs }) {
  return ['recipe-run', '--recipe', recipe, '--artifacts', artifacts, '--timeout', String(timeoutMs), '--preview-hold-seconds', String(Math.ceil(timeoutMs / 1000)), '--preview-hold-blocking', '--preview-port', String(port), '--preview-bind', '127.0.0.1'];
}

/** The configured producer's capture and compare argv for one composed checkout. */
export function captureCommands(producer, { checkout, url, output, capture, candidate }) {
  return {
    capture: expandTemplate(producer.capture, { checkout, url, output }),
    compare: (stage) => [...expandTemplate(producer.compare, { checkout, capture, stage }), ...(stage === 'materialization' ? expandTemplate(producer.compare_candidate, { candidate }) : [])],
    report: (stage) => expandTemplate(producer.compare_report, { capture, stage }),
  };
}

/** Find the single capture under `output` by its Blocks Engine capture receipt. */
function jsonPointer(value, pointer) {
  return pointer.slice(1).split('/').map((part) => part.replace(/~1/g, '/').replace(/~0/g, '~')).reduce((current, key) => current?.[key], value);
}

function truncateStrings(value) {
  if (typeof value === 'string') return value.slice(0, 500);
  if (Array.isArray(value)) return value.map(truncateStrings);
  if (value && typeof value === 'object') return Object.fromEntries(Object.entries(value).map(([key, item]) => [key, truncateStrings(item)]));
  return value;
}

export function locateCapture(output, failureDetails) {
  const receipts = [output, ...(fs.existsSync(output) ? fs.readdirSync(output, { withFileTypes: true }).filter((entry) => entry.isDirectory()).map((entry) => path.join(output, entry.name)) : [])]
    .filter((directory) => fs.existsSync(path.join(directory, CAPTURE_RECEIPT_FILE)));
  if (receipts.length !== 1) return null;
  const receipt = readJson(path.join(receipts[0], CAPTURE_RECEIPT_FILE));
  const website = path.join(receipts[0], receipt.websiteRoot || 'website');
  if (!fs.existsSync(path.join(website, 'index.html'))) return null;
  const summary = receipt.summary || {};
  const failures = Number(summary.routesFailed || 0) > 0 ? [{ routes_failed: summary.routesFailed, routes_captured: summary.routesCaptured ?? null, receipt_path: path.join(receipts[0], CAPTURE_RECEIPT_FILE), ...(failureDetails ? { routes: (() => { try { const file = expandTemplate(failureDetails.file, { capture: receipts[0] }); const value = fs.existsSync(file) ? jsonPointer(readJson(file), failureDetails.pointer) : null; return Array.isArray(value) ? value.slice(0, 50).map(truncateStrings) : undefined; } catch { return undefined; } })() } : {}) }] : [];
  return { directory: receipts[0], website, failures };
}

/** Read the SSI CLI state file: terminal quality, failures and success. */
export function readImportState(state) {
  const terminal = state?.terminal;
  if (!terminal) return null;
  const summary = terminal.result?.import_report_summary || terminal.import_report_summary || null;
  const quality = summary ? { quality_pass: summary.quality_pass === true, failure_reasons: summary.failure_reasons || [], ...Object.fromEntries(LOSS_COUNTS.map((key) => [key, Number(summary[key] || 0)])) } : { quality_pass: false, failure_reasons: [] };
  const importFailures = terminal.success === false ? [{ code: terminal.error?.code || 'import_failed', message: terminal.error?.message || null }] : [];
  return { success: terminal.success === true, quality, import_failures: importFailures };
}

/** Compact, citable result from a capture producer compare report (`pass`, `status`, optional scores). */
export function compareResult(reportPath) {
  const report = readJsonIfPresent(reportPath);
  if (!report) return null;
  const scores = Array.isArray(report.scores) ? report.scores.filter((score) => score?.pass === false) : [];
  const failing = scores.slice(0, 50).map((score) => Object.fromEntries(['route', 'viewport', 'width', 'state', 'failures'].filter((key) => Object.hasOwn(score, key)).map((key) => [key, score[key]])));
  return { passed: report.pass === true, status: report.status || null, report_path: reportPath, failing_count: scores.length, scores: failing, pending: Array.isArray(report.pending) ? report.pending.slice(0, 50) : report.pending ?? null };
}

function run(program, args, options = {}) {
  const result = spawnSync(program, args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024, ...options });
  if (options.log) fs.writeFileSync(options.log, `$ ${program} ${args.join(' ')}\n${result.stdout || ''}\n${result.stderr || ''}`);
  return result;
}

function git(cwd, args, reason) {
  const result = run('git', args, { cwd });
  if (result.status !== 0) throw new LoopBlocker(reason, (result.stderr || result.stdout || '').trim().slice(-2000));
  return result.stdout.trim();
}

/**
 * Detached checkout of one repository's remote base with this instance's
 * repair branches merged in iteration order. A conflict is a typed blocker.
 */
export function composeRepository({ repo, source, remote, base, instance, directory }) {
  const namespace = `refs/url-loop/${instance}`;
  git(source, ['fetch', '--quiet', '--no-tags', remote, `+refs/heads/${base}:${namespace}/base/${repo}`, `+refs/heads/url-loop/${instance}/*:${namespace}/branches/${repo}/*`], `candidate_fetch_failed:${repo}`);
  const baseSha = git(source, ['rev-parse', `${namespace}/base/${repo}`], `candidate_base_missing:${repo}`);
  const listed = git(source, ['for-each-ref', '--format=%(refname) %(objectname)', `${namespace}/branches/${repo}/`], `candidate_branch_listing_failed:${repo}`);
  const refs = listed.split('\n').filter(Boolean).map((line) => {
    const [ref, sha] = line.split(' ');
    return { branch: `url-loop/${instance}/${ref.slice(`${namespace}/branches/${repo}/`.length)}`, sha };
  });
  const branches = orderRepairBranches(refs, instance, repo);
  fs.mkdirSync(path.dirname(directory), { recursive: true });
  git(source, ['worktree', 'add', '--quiet', '--detach', directory, baseSha], `candidate_checkout_failed:${repo}`);
  for (const branch of branches) {
    const merged = run('git', ['-c', 'user.name=url-loop', '-c', 'user.email=url-loop@localhost', 'merge', '--quiet', '--no-edit', branch.sha], { cwd: directory });
    if (merged.status !== 0) {
      run('git', ['merge', '--abort'], { cwd: directory });
      throw new LoopBlocker(`candidate_merge_conflict:${repo}:${branch.branch}`, (merged.stdout || merged.stderr || '').trim().slice(-2000));
    }
  }
  return { repository: repo, base, base_sha: baseSha, merged: branches.map(({ branch, sha }) => ({ branch, sha })), head_sha: git(directory, ['rev-parse', 'HEAD'], `candidate_head_missing:${repo}`), path: directory };
}

function freePort() {
  return new Promise((resolve, reject) => {
    const server = net.createServer();
    server.once('error', reject);
    server.listen(0, '127.0.0.1', () => { const { port } = server.address(); server.close(() => resolve(port)); });
  });
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Import the capture into a disposable Codebox WordPress, keep it served until
 * the import is terminal, run `materialize(origin)` against it, then stop it.
 */
async function importAndMeasure({ context, pluginZip, captureWebsite, url, directory, materialize }) {
  const stage = path.join(directory, 'import-stage');
  fs.mkdirSync(stage, { recursive: true });
  fs.cpSync(captureWebsite, path.join(stage, 'website'), { recursive: true });
  writeJson(path.join(stage, 'request.json'), importRequest(url));
  const recipe = path.join(directory, 'codebox-recipe.json');
  writeJson(recipe, codeboxRecipe({ pluginZip, stage, wordpressVersion: context.wordpress_version, importSteps: context.import_steps }));
  const port = await freePort();
  const timeoutMs = 60 * 60 * 1000;
  const log = fs.openSync(path.join(directory, 'codebox.log'), 'w');
  const child = spawn(context.wp_codebox_bin, codeboxArgs({ recipe, artifacts: path.join(directory, 'codebox-artifacts'), port, timeoutMs }), { cwd: directory, detached: true, stdio: ['ignore', log, log] });
  let exited = false;
  child.on('exit', () => { exited = true; });
  const stop = () => { if (!exited) { try { process.kill(-child.pid, 'SIGTERM'); } catch { /* already gone */ } } };
  try {
    const statePath = path.join(stage, 'state.json');
    const origin = `http://127.0.0.1:${port}/`;
    const deadline = Date.now() + timeoutMs;
    for (;;) {
      const imported = readImportState(readJsonIfPresent(statePath));
      if (imported) {
        const served = await fetch(origin, { signal: AbortSignal.timeout(10000) }).then((response) => response.status === 200, () => false);
        if (served) return { imported, state_path: statePath, finding_packets_path: path.join(stage, 'finding-packets.json'), materialization: materialize(origin) };
      }
      if (exited) throw new LoopBlocker(imported ? 'import_preview_unavailable' : 'import_not_terminal', path.join(directory, 'codebox.log'));
      if (Date.now() > deadline) throw new LoopBlocker('import_timed_out', path.join(directory, 'codebox.log'));
      await sleep(5000);
    }
  } finally {
    stop();
    fs.closeSync(log);
  }
}

/**
 * One iteration: compose the candidate, build the capture producer and the SSI development
 * package, capture and compare the live source, import into Codebox, compare
 * WordPress against the capture, and decide the verdict with routed repairs.
 */
export async function runEvaluation({ context, url, instance, iteration }) {
  const normalized = normalizeUrl(url);
  if (!context?.root || context.source_id !== sourceIdentity(normalized)) throw new Error('workflow_context_url_mismatch');
  if (!/^[A-Za-z0-9_.-]{1,96}$/.test(String(instance || '')) || !/^[1-9][0-9]*$/.test(String(iteration || ''))) throw new Error('evaluation_identity_invalid');
  const directory = path.join(context.root, 'evaluations', String(instance), `i${iteration}`);
  if (fs.existsSync(directory)) throw new Error('evaluation_output_not_fresh');
  fs.mkdirSync(path.join(directory, 'logs'), { recursive: true });
  const reportPath = path.join(directory, 'evaluation.json');
  const env = { ...process.env, ...context.runtime_toolchain?.env };
  const components = {};
  const evidence = {};
  let blocker = null;
  try {
    for (const [repo, { path: source, remote, base }] of Object.entries(context.repositories)) {
      components[repo] = composeRepository({ repo, source, remote, base, instance, directory: path.join(directory, 'candidate', repo) });
    }
    const producer = context.capture_producer;
    const captureCheckout = components[producer.repository].path;
    for (const [index, command] of producer.build.entries()) {
      const [program, ...args] = expandTemplate(command, { checkout: captureCheckout });
      if (run(program, args, { cwd: captureCheckout, env, log: path.join(directory, 'logs', `capture-build-${index}.log`) }).status !== 0) throw new LoopBlocker(`capture_build_failed:${index}`);
    }
    const ssi = components['static-site-importer'].path;
    const packageDir = path.join(directory, 'ssi-package');
    if (run(context.runtime_toolchain.node, ['tools/build-dev-package.mjs', '--blocks-engine-path', components['blocks-engine'].path, '--output-dir', packageDir], { cwd: ssi, env, log: path.join(directory, 'logs', 'ssi-package.log') }).status !== 0) throw new LoopBlocker('ssi_package_failed');
    const pluginZip = fs.readdirSync(packageDir).filter((file) => file.endsWith('.zip')).map((file) => path.join(packageDir, file))[0];
    if (!pluginZip) throw new LoopBlocker('ssi_package_missing');

    const output = path.join(directory, 'capture');
    const [captureProgram, ...captureArgs] = captureCommands(producer, { checkout: captureCheckout, url: normalized, output }).capture;
    run(captureProgram, captureArgs, { cwd: directory, env, log: path.join(directory, 'logs', 'capture.log') });
    const located = locateCapture(output, producer.failure_details);
    if (!located) throw new LoopBlocker('capture_receipt_missing', path.join(directory, 'logs', 'capture.log'));
    evidence.capture_repository = producer.repository;
    evidence.capture_failures = located.failures;
    const commands = captureCommands(producer, { checkout: captureCheckout, capture: located.directory });
    const compare = (stage, candidate) => {
      const [program, ...args] = captureCommands(producer, { checkout: captureCheckout, capture: located.directory, candidate }).compare(stage);
      run(program, args, { cwd: directory, env, log: path.join(directory, 'logs', `compare-${stage}.log`) });
      return compareResult(commands.report(stage));
    };
    evidence.capture_compare = compare('capture');

    const measured = await importAndMeasure({
      context, pluginZip, captureWebsite: located.website, url: normalized, directory,
      materialize: (origin) => compare('materialization', origin),
    });
    evidence.quality = measured.imported.quality;
    evidence.import_failures = measured.imported.import_failures;
    evidence.finding_packets = readJsonIfPresent(measured.finding_packets_path)?.packets || [];
    evidence.materialization_compare = measured.materialization;
    evidence.import_state_path = measured.state_path;
  } catch (error) {
    if (!(error instanceof LoopBlocker)) throw error;
    blocker = { reason: error.reason, detail: error.detail };
  } finally {
    // Includes a checkout left behind by a merge conflict.
    for (const [repo, { path: source }] of Object.entries(context.repositories)) {
      const checkout = path.join(directory, 'candidate', repo);
      if (fs.existsSync(checkout)) run('git', ['worktree', 'remove', '--force', checkout], { cwd: source });
    }
  }

  const decided = blocker ? { verdict: 'blocked', repairs: {}, loss_count: null, blocker: blocker.reason, acceptance: { solved: false, scope: 'loop_stop_condition' } } : evaluateLocalEvidence(evidence);
  const componentRecord = Object.fromEntries(Object.entries(components).map(([repo, record]) => [repo, { base: record.base, base_sha: record.base_sha, merged: record.merged, head_sha: record.head_sha }]));
  writeRepairEvidence(directory, decided.repairs, {
    url: normalized,
    instance,
    iteration: Number(iteration),
    components: componentRecord,
    capture_compare: evidence.capture_compare || null,
    materialization_compare: evidence.materialization_compare || null,
    quality: evidence.quality || null,
    import_state_path: evidence.import_state_path || null,
  });
  const evaluation = { schema: EVALUATION_ARTIFACT_SCHEMA, url: normalized, loop_id: context.loop_id, instance, iteration: Number(iteration), components: componentRecord, ...evidence, ...decided, blocker_detail: blocker?.detail || null };
  writeJson(reportPath, evaluation);
  return { evaluation, report_path: reportPath };
}

/** The evaluate step output the workflow gates and Cook evidence read. */
export function evaluationVerdict(evaluation, reportPath) {
  return {
    verdict: evaluation.verdict,
    report_path: reportPath,
    blocker: evaluation.blocker || null,
    loss_count: evaluation.loss_count,
    capture_compare: evaluation.capture_compare?.passed ?? null,
    materialization_compare: evaluation.materialization_compare?.passed ?? null,
    quality_pass: evaluation.quality?.quality_pass ?? null,
    components: Object.fromEntries(Object.entries(evaluation.components || {}).map(([repo, record]) => [repo, { head_sha: record.head_sha, merged: record.merged.map((item) => item.branch) }])),
    // Compact: the findings live in each repository's evidence file.
    repairs: Object.fromEntries(Object.entries(evaluation.repairs || {}).map(([repo, work]) => [repo, { findings: work.findings.length, report_path: work.report_path }])),
  };
}

const REPEATABLE = new Set(['ssiVerify', 'blocksEngineVerify']);
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

const USAGE = 'usage: url-loop-controller.mjs start|workflow --url <url> --capture-producer <JSON> --task-url <url> [--blocks-engine <path>] [--wp-codebox-bin <path>] [--output-root <path>] [--instance <token>] [--ssi-task-url <url>] [--blocks-engine-task-url <url>] [--ssi-verify <command>]... [--blocks-engine-verify <command>]... [--fix-placement local] [--fix-backend <backend>] [--wordpress-version 7.1] [--import-steps 24] [--max-iterations 3] [--max-step-runs 20] [--deadline 10h]';

async function main() {
  const [operation, ...argv] = process.argv.slice(2);
  const options = parseArgs(argv);
  if (operation === 'evaluate') {
    const { evaluation, report_path: reportPath } = await runEvaluation({ context: JSON.parse(options.context), url: options.url, instance: options.instance, iteration: options.iteration });
    const verdict = evaluationVerdict(evaluation, reportPath);
    writeStepOutput(verdict);
    // A typed blocker fails the step, and with it the workflow instance.
    if (verdict.verdict === 'blocked') throw new Error(`evaluation_blocked:${verdict.blocker}`);
    return;
  }
  if (operation === 'workflow') { process.stdout.write(`${JSON.stringify(buildUrlProofWorkflow(options).workflow, null, 2)}\n`); return; }
  if (operation === 'start') {
    const { workflow, context, inputs } = buildUrlProofWorkflow(options);
    const file = path.join(context.root, 'workflow.json');
    writeJson(file, workflow);
    const result = spawnSync(options.homeboyBin || 'homeboy', ['workflow', 'run', file, '--input', `url=${inputs.url}`], { cwd: context.workspace, stdio: ['ignore', 'pipe', 'inherit'], encoding: 'utf8' });
    if (result.status !== 0) { process.stdout.write(result.stdout || ''); throw new Error(`workflow_admission_failed:${result.status}`); }
    const instance = JSON.parse(result.stdout).data?.instance;
    process.stdout.write(`${JSON.stringify({ instance, loop_id: context.loop_id, workflow_file: file, status_command: `homeboy workflow status ${instance}` })}\n`);
    return;
  }
  throw new Error(USAGE);
}

if (process.argv[1] === MODULE_PATH) main().catch((error) => { process.stderr.write(`${error.message}\n`); process.exitCode = 1; });
