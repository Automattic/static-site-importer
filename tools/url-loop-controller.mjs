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
export const REPAIR_TRACKER = 'https://github.com/Automattic/static-site-importer/issues/1874';
const MODULE_PATH = fileURLToPath(import.meta.url);
const STAGE_MOUNT = '/url-loop';
const LOSS_COUNTS = ['unsupported_fallback_count', 'core_html_block_count', 'freeform_block_count', 'invalid_block_count'];

/**
 * The three repositories the loop composes and repairs. `option` is the CLI
 * option prefix (`--<option>-task-url`, `--<option>-verify`, `--<option>` path).
 */
export const REPOSITORIES = {
  'static-site-importer': { step: 'fix-ssi', option: 'ssi', base: 'main', remote: 'https://github.com/Automattic/static-site-importer.git', verify: 'npm test' },
  'blocks-engine': { step: 'fix-blocks-engine', option: 'blocksEngine', base: 'trunk', remote: 'https://github.com/Automattic/blocks-engine.git', verify: 'composer --working-dir=php-transformer test' },
  'data-liberation-agent': { step: 'fix-dla', option: 'dla', base: 'main', remote: 'https://github.com/Automattic/data-liberation-agent.git', verify: 'npm test' },
};
export const REPAIR_REPOS = Object.keys(REPOSITORIES);

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
  if (!REPOSITORIES[repo]) throw new Error(`unknown_repair_repository:${repo}`);
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
 * candidate composed from the three repository tips plus this instance's
 * repair branches, and each repository with routed findings gets a repair
 * Cook. The loop stops when the verdict is `accepted` or a budget ends it.
 */
export function buildUrlProofWorkflow(input) {
  const url = normalizeUrl(input.url);
  const workspace = path.resolve(input.workspace || process.cwd());
  const paths = {
    'static-site-importer': workspace,
    'blocks-engine': input.blocksEngine || process.env.SSI_BLOCKS_ENGINE_PATH,
    'data-liberation-agent': input.dla || process.env.SSI_DLA_PATH,
  };
  const wpCodeboxBin = input.wpCodeboxBin || process.env.HOMEBOY_WP_CODEBOX_BIN;
  if (!paths['blocks-engine'] || !paths['data-liberation-agent'] || !wpCodeboxBin) throw new Error('blocks-engine, dla and wp-codebox-bin checkouts must be configured on this runner');
  const maxIterations = boundedInteger(input.maxIterations, 3, 1, 5, 'max-iterations');
  const maxStepRuns = boundedInteger(input.maxStepRuns, 20, 4, 30, 'max-step-runs');
  const deadline = String(input.deadline || '10h');
  if (!/^[1-9][0-9]*[smhd]$/.test(deadline)) throw new Error('deadline must be a duration such as 90m or 10h');
  const sourceId = sourceIdentity(url);
  if (input.instance && !/^[A-Za-z0-9_-]{1,32}$/.test(input.instance)) throw new Error('instance must be a short path-safe token');
  const loopId = input.instance ? `${sourceId}-${input.instance}` : sourceId;
  const root = path.resolve(input.outputRoot || path.join(workspace, 'artifacts', 'url-loop', loopId));
  const node = path.resolve(input.nodeExecutable || process.execPath);
  const context = {
    source_id: sourceId,
    loop_id: loopId,
    root,
    workspace,
    repositories: Object.fromEntries(REPAIR_REPOS.map((repo) => [repo, { path: path.resolve(paths[repo]), remote: REPOSITORIES[repo].remote, base: REPOSITORIES[repo].base }])),
    wp_codebox_bin: path.resolve(wpCodeboxBin),
    wordpress_version: String(input.wordpressVersion || '7.1'),
    route_limit: boundedInteger(input.routeLimit, 12, 1, 200, 'route-limit'),
    reference_sample: String(input.referenceSample || 'all'),
    import_steps: boundedInteger(input.importSteps, 24, 1, 200, 'import-steps'),
    runtime_toolchain: { node, env: Object.fromEntries(RUNTIME_ENV.filter((key) => key !== 'PATH' && process.env[key]).map((key) => [key, process.env[key]])) },
  };
  // The context is part of the pinned definition, so it is digested into the
  // instance identity and can never drift under a running instance.
  const evaluate = { program: node, args: [MODULE_PATH, 'evaluate', '--url', '{{inputs.url}}', '--instance', '{{instance}}', '--iteration', '{{iteration}}', '--context', JSON.stringify(context)], cwd: workspace };
  const cook = (repo) => {
    const { option, verify } = REPOSITORIES[repo];
    const head = repairBranch('{{instance}}', repo, '{{iteration}}');
    return {
      repo,
      'task-url': input[`${option}TaskUrl`] || REPAIR_TRACKER,
      head,
      placement: input.fixPlacement || 'local',
      ...(input.fixBackend ? { backend: input.fixBackend } : {}),
      'provider-evidence': JSON.stringify({ id: `${repo}-evaluation`, source: `{{steps.evaluate.output/repairs/${repo}/report_path}}` }),
      prompt: `Repair the ${repo} findings for {{inputs.url}} (URL repair loop {{instance}}, iteration {{iteration}}). The ${repo}-evaluation provider evidence is the scoped work list with the composed candidate SHAs and stage reports. Fix the cause generically in ${repo}; the next iteration measures this branch.`,
      verify: [input[`${option}Verify`] || verify].flat(),
      'draft-pr': false,
    };
  };
  const workflow = {
    schema: WORKFLOW_SCHEMA,
    id: WORKFLOW_ID,
    inputs: { url: { required: true } },
    env: { public: RUNTIME_ENV.filter((name) => process.env[name]) },
    budget: { max_step_runs: maxStepRuns, deadline },
    iterate: { until: { step: 'evaluate', output: '/verdict', equals: 'accepted' }, max_iterations: maxIterations },
    steps: [
      { id: 'evaluate', timeout: '90m', exec: evaluate },
      ...REPAIR_REPOS.map((repo) => ({ id: REPOSITORIES[repo].step, needs: ['evaluate'], when: { step: 'evaluate', output: `/repairs/${repo}`, exists: true }, retry: { max: 1, on: ['failed'] }, cook: cook(repo) })),
    ],
  };
  return { workflow, context, inputs: { url } };
}

/** Route evidence to exactly the repository that owns the repair. */
export function routeFindings({ captureFailures = [], captureCompare, packets = [], importFailures = [], materializationCompare } = {}) {
  const repairs = Object.fromEntries(REPAIR_REPOS.map((repo) => [repo, { findings: [], report_path: null }]));
  const add = (repo, item) => repairs[repo].findings.push(item);
  for (const failure of captureFailures) add('data-liberation-agent', { kind: 'capture_failure', ...failure });
  if (captureCompare?.passed === false) add('data-liberation-agent', { kind: 'capture_compare_failed', ...captureCompare });
  for (const packet of packets) add(String(packet.owner || '').startsWith('blocks-engine/') ? 'blocks-engine' : 'static-site-importer', { kind: 'finding_packet', ...packet });
  for (const failure of importFailures) add('static-site-importer', { kind: 'import_failure', ...failure });
  if (materializationCompare?.passed === false) add('static-site-importer', { kind: 'materialization_compare_failed', ...materializationCompare });
  return Object.fromEntries(Object.entries(repairs).filter(([, work]) => work.findings.length));
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
  const routed = routeFindings({ captureFailures: evidence.capture_failures, captureCompare: evidence.capture_compare, packets: evidence.finding_packets, importFailures: evidence.import_failures, materializationCompare: evidence.materialization_compare });
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

export function dlaCaptureArgs(cli, { url, output, routeLimit, referenceSample }) {
  return [cli, url, '--output', output, '--route-limit', String(routeLimit), '--reference-sample', String(referenceSample)];
}

export function dlaCompareArgs(cli, { captureDir, stage, candidate }) {
  return [cli, 'compare', captureDir, '--stage', stage, ...(candidate ? ['--candidate', candidate] : [])];
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

/** Compact, citable result from a DLA compare report. */
export function compareResult(reportPath) {
  const report = readJsonIfPresent(reportPath);
  if (!report) return null;
  return { passed: report.pass === true, status: report.status || null, report_path: reportPath, failed: Array.isArray(report.failed) ? report.failed.slice(0, 20) : report.failed ?? null };
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

function captureFailures(captureDir) {
  const diagnostics = readJsonIfPresent(path.join(captureDir, 'diagnostics.json'));
  return (diagnostics?.failures || []).map((failure) => ({ url: failure.url, viewport: failure.viewport, stage: failure.stage, error: String(failure.error || '').slice(0, 500) }));
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
 * One iteration: compose the candidate, build DLA and the SSI development
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
    for (const repo of REPAIR_REPOS) {
      const { path: source, remote, base } = context.repositories[repo];
      components[repo] = composeRepository({ repo, source, remote, base, instance, directory: path.join(directory, 'candidate', repo) });
    }
    const dla = components['data-liberation-agent'].path;
    for (const [args, reason] of [[['ci', '--no-audit', '--no-fund'], 'dla_install_failed'], [['run', 'build'], 'dla_build_failed']]) {
      if (run('npm', args, { cwd: dla, env, log: path.join(directory, 'logs', `dla-${args[0]}.log`) }).status !== 0) throw new LoopBlocker(reason);
    }
    const ssi = components['static-site-importer'].path;
    const packageDir = path.join(directory, 'ssi-package');
    if (run(context.runtime_toolchain.node, ['tools/build-dev-package.mjs', '--blocks-engine-path', components['blocks-engine'].path, '--output-dir', packageDir], { cwd: ssi, env, log: path.join(directory, 'logs', 'ssi-package.log') }).status !== 0) throw new LoopBlocker('ssi_package_failed');
    const pluginZip = fs.readdirSync(packageDir).filter((file) => file.endsWith('.zip')).map((file) => path.join(packageDir, file))[0];
    if (!pluginZip) throw new LoopBlocker('ssi_package_missing');

    const cli = path.join(dla, 'dist', 'cli.js');
    const captureRoot = path.join(directory, 'capture');
    run(context.runtime_toolchain.node, dlaCaptureArgs(cli, { url: normalized, output: captureRoot, routeLimit: context.route_limit, referenceSample: context.reference_sample }), { cwd: directory, env, log: path.join(directory, 'logs', 'dla-capture.log') });
    const captureDir = path.join(captureRoot, new URL(normalized).hostname);
    if (!fs.existsSync(path.join(captureDir, 'website', 'index.html'))) throw new LoopBlocker('capture_failed', path.join(directory, 'logs', 'dla-capture.log'));
    evidence.capture_failures = captureFailures(captureDir);
    run(context.runtime_toolchain.node, dlaCompareArgs(cli, { captureDir, stage: 'capture' }), { cwd: directory, env, log: path.join(directory, 'logs', 'compare-capture.log') });
    evidence.capture_compare = compareResult(path.join(captureDir, 'compare', 'capture', 'report.json'));

    const measured = await importAndMeasure({
      context, pluginZip, captureWebsite: path.join(captureDir, 'website'), url: normalized, directory,
      materialize: (origin) => {
        run(context.runtime_toolchain.node, dlaCompareArgs(cli, { captureDir, stage: 'materialization', candidate: origin }), { cwd: directory, env, log: path.join(directory, 'logs', 'compare-materialization.log') });
        return compareResult(path.join(captureDir, 'compare', 'materialization', 'report.json'));
      },
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
    for (const repo of REPAIR_REPOS) {
      const checkout = path.join(directory, 'candidate', repo);
      if (fs.existsSync(checkout)) run('git', ['worktree', 'remove', '--force', checkout], { cwd: context.repositories[repo].path });
    }
  }

  const decided = blocker ? { verdict: 'blocked', repairs: {}, loss_count: null, blocker: blocker.reason, acceptance: { solved: false, scope: 'loop_stop_condition' } } : evaluateLocalEvidence(evidence);
  const componentRecord = Object.fromEntries(Object.entries(components).map(([repo, { path: _path, ...record }]) => [repo, record]));
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

const REPEATABLE = new Set(['ssiVerify', 'blocksEngineVerify', 'dlaVerify']);
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

const USAGE = 'usage: url-loop-controller.mjs start|workflow --url <url> [--blocks-engine <path>] [--dla <path>] [--wp-codebox-bin <path>] [--output-root <path>] [--instance <token>] [--ssi-task-url <url>] [--blocks-engine-task-url <url>] [--dla-task-url <url>] [--ssi-verify <command>]... [--blocks-engine-verify <command>]... [--dla-verify <command>]... [--fix-placement local] [--fix-backend <backend>] [--wordpress-version 7.1] [--route-limit 12] [--reference-sample all] [--import-steps 24] [--max-iterations 3] [--max-step-runs 20] [--deadline 10h]';

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
