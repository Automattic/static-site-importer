#!/usr/bin/env node

import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const RIG_ID = 'static-site-importer-fixture-matrix';
// Expected top-level fixture directory count in the canonical corpus
// (`blocks-engine/fixtures/websites`). This is an intentional drift guard, not a
// derived value: the corpus lives in a different repo, so deriving the canonical
// number from whatever happens to be on disk would make the check tautological
// (always "matches") and defeat its purpose. Bump this deliberately when the
// corpus grows/shrinks. Source of truth:
//   git -C blocks-engine ls-tree -d --name-only origin/trunk:fixtures/websites | wc -l
// Last verified against origin/trunk @ 8ad42fd = 72 directories.
export const CANONICAL_FIXTURE_COUNT = 72;

const packageRoot = path.dirname(path.dirname(fileURLToPath(import.meta.url)));

async function main() {
  const options = parseArgs(process.argv.slice(2));
  if (options.help) {
    printHelp();
    return;
  }

  const plan = buildFixtureMatrixRunPlan(options);

  if (plan.code_freshness.would_block) {
    process.stderr.write(freshnessGuardBanner(plan.code_freshness, options));
  }

  if (options.dryRun) {
    process.stdout.write(`${JSON.stringify(plan, null, 2)}\n`);
    return;
  }

  if (plan.code_freshness.would_block && !options.allowStaleOverride) {
    process.exitCode = 1;
    return;
  }

  fs.mkdirSync(path.dirname(plan.output_file), { recursive: true });

  // Install/sync run first; a non-zero exit there is a genuine setup failure and
  // should still abort via runCommand's throw.
  const benchStep = plan.steps.at(-1);
  for (const step of plan.steps.slice(0, -1)) {
    runCommand(step);
  }

  // The bench step exits non-zero on a gate-FAIL (one or more fixtures failed).
  // That is an expected, summarizable outcome — not a fatal error — so capture
  // its exit status instead of throwing. summarizeBenchRun only throws when the
  // bench genuinely crashed (no parseable result payload written to --output).
  const benchStatus = runStep(benchStep);
  const { summary, gateFailed } = summarizeBenchRun({
    plan,
    benchStatus,
    benchLabel: benchStep.label,
  });
  process.stdout.write(`${JSON.stringify(summary, null, 2)}\n`);
  if (gateFailed) {
    process.exitCode = 1;
  }
}

export function buildFixtureMatrixRunPlan(input) {
  const options = normalizeOptions(input);
  const settings = {
    SSI_FIXTURE_MATRIX_FIXTURE_ROOT: options.fixtureRoot,
    SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH: options.staticSiteImporter,
    SSI_FIXTURE_MATRIX_RUN: '1',
    ...(options.blocksEnginePhpTransformerPath
      ? { SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH: options.blocksEnginePhpTransformerPath }
      : {}),
    ...(options.batchSize ? { SSI_FIXTURE_MATRIX_BATCH_SIZE: String(options.batchSize) } : {}),
    ...(options.concurrency ? { SSI_FIXTURE_MATRIX_CONCURRENCY: String(options.concurrency) } : {}),
    ...(options.wordpressVersion ? { SSI_FIXTURE_MATRIX_WORDPRESS_VERSION: options.wordpressVersion } : {}),
    ...(options.wpCodeboxBin ? { SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN: options.wpCodeboxBin } : {}),
    ...(options.editorValidation === false ? { SSI_FIXTURE_MATRIX_EDITOR_VALIDATION: '0' } : {}),
    ...(options.visualParity === false ? { SSI_FIXTURE_MATRIX_VISUAL_PARITY: '0' } : {}),
    ...(options.visualParityGate ? { SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE: '1' } : {}),
    ...(options.pixelThreshold ? { SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXEL_THRESHOLD: String(options.pixelThreshold) } : {}),
    // Opt-in live-WP parity capture (off by default). When set, the bench appends
    // the deterministic `wordpress.capture-html` step per fixture and runs the
    // blocks-engine live-wp-parity comparator host-side. Absent => byte-identical
    // to today (no setting emitted, render-free static gate stays primary).
    ...(options.liveWpParity ? { SSI_FIXTURE_MATRIX_LIVE_WP_PARITY: '1' } : {}),
    ...(options.minNativeRate ? { SSI_FIXTURE_MATRIX_MIN_NATIVE_RATE: String(options.minNativeRate) } : {}),
    // Lane/tag selection driven by the per-fixture manifest classification: run a
    // single class lane (e.g. marketing/static) and/or only fixtures carrying a
    // given manifest tag.
    ...(options.class ? { SSI_FIXTURE_MATRIX_CLASS: String(options.class) } : {}),
    ...(options.tag ? { SSI_FIXTURE_MATRIX_TAG: String(options.tag) } : {}),
  };
  const fixtureCount = countTopLevelFixtureDirectories(options.fixtureRoot);
  const codeFreshness = buildCodeFreshness(options, options.gitRunner || defaultGitRunner);
  const warnings = [
    ...buildWarnings(options),
    ...buildCanonicalDriftWarnings(fixtureCount, options.fixtureRoot),
    ...buildFreshnessWarnings(codeFreshness, options),
  ];

  return {
    schema: 'static-site-importer/fixture-matrix-operator-run/v1',
    mode: options.mode,
    rig: RIG_ID,
    runner: options.runner,
    local: Boolean(options.local),
    run_id: options.runId,
    static_site_importer: options.staticSiteImporter,
    blocks_engine: options.blocksEngine,
    homeboy_bin: options.homeboyBin,
    fixture_root: options.fixtureRoot,
    fixture_count: fixtureCount,
    canonical_fixture_count: CANONICAL_FIXTURE_COUNT,
    fixture_count_matches_canonical: fixtureCount === CANONICAL_FIXTURE_COUNT,
    output_file: options.output,
    temp_root: options.tempRoot,
    artifact_root: options.artifactRoot,
    shared_state: options.sharedState,
    namespace: options.namespace,
    allow_stale_override: Boolean(options.allowStaleOverride),
    visual_parity: {
      enabled: options.visualParity !== false,
      // Opt-in hard gate; default capture-only because pixel diffs can be flaky.
      gate: Boolean(options.visualParityGate),
      pixel_threshold: options.pixelThreshold ? Number(options.pixelThreshold) : null,
    },
    editor_quality: {
      // Editor-quality metrics (native_conversion_rate, core_html_fallback_ratio,
      // editor_invalid_count) are always scored and emitted. The native-rate gate
      // is opt-in and off by default, mirroring --visual-parity-gate.
      native_rate_gate: Boolean(options.minNativeRate),
      min_native_rate: options.minNativeRate ? Number(options.minNativeRate) : null,
    },
    editor_validation: {
      // The wordpress.editor-validate-blocks step launches a browser per site and
      // is the slowest per-fixture step. --no-editor-validation skips it (mirroring
      // --no-visual-parity) so a run still produces native-rate/loss-classes/
      // findings, just without the validateBlock editor-validity data.
      enabled: options.editorValidation !== false,
    },
    code_freshness: codeFreshness,
    transformer_commit: resolveTransformerCommit(codeFreshness),
    warnings,
    dependency_overrides: options.blocksEnginePhpTransformerPath
      ? { blocks_engine_php_transformer: { path: options.blocksEnginePhpTransformerPath } }
      : {},
    steps: buildSteps(options, settings),
  };
}

function normalizeOptions(input) {
  if (!input.staticSiteImporter) {
    throw new Error('--static-site-importer is required');
  }

  const blocksEngine = input.blocksEngine ? path.resolve(input.blocksEngine) : '';
  if (!input.fixtureRoot && !blocksEngine) {
    throw new Error('--blocks-engine or --fixture-root is required');
  }
  const fixtureRoot = path.resolve(input.fixtureRoot || path.join(blocksEngine, 'fixtures', 'websites'));

  const defaultBlocksEnginePhpTransformerPath = input.mode === 'release-proof' ? '' : blocksEngine;
  const blocksEnginePhpTransformerPath = input.blocksEnginePhpTransformerPath === undefined
    ? defaultBlocksEnginePhpTransformerPath
    : (input.blocksEnginePhpTransformerPath ? path.resolve(input.blocksEnginePhpTransformerPath) : '');
  const mode = input.mode || (blocksEnginePhpTransformerPath ? 'development-override' : 'release-proof');
  const runId = input.runId || `ssi-matrix-${mode}-${timestamp()}`;
  const namespace = sanitizePathSegment(input.namespace || runId);
  const tempRoot = input.tempRoot ? path.resolve(input.tempRoot) : defaultTempRoot(namespace, input);
  const output = path.resolve(input.output || path.join(process.cwd(), 'artifacts', `${runId}.homeboy-bench.json`));

  return {
    ...input,
    mode,
    runId,
    namespace,
    tempRoot,
    output,
    fixtureRoot,
    blocksEngine,
    blocksEnginePhpTransformerPath,
    passthrough: Array.isArray(input.passthrough) ? input.passthrough : [],
    staticSiteImporter: path.resolve(input.staticSiteImporter),
    sharedState: input.sharedState ? path.resolve(input.sharedState) : path.join(tempRoot, 'shared-state'),
    artifactRoot: input.artifactRoot ? path.resolve(input.artifactRoot) : path.join(tempRoot, 'artifacts'),
    runner: input.runner || '',
    homeboyBin: input.homeboyBin || process.env.HOMEBOY_BIN || 'homeboy',
  };
}

function defaultTempRoot(namespace) {
  return path.resolve(path.join('/tmp', `static-site-importer-fixture-matrix-${namespace}`));
}

function buildWarnings(options) {
  return [
    ...(!options.runner && !options.labOnly && !options.local ? [{
      code: 'lab_auto_offload_risk',
      message: 'No --runner/--lab-only/--local routing was provided. `homeboy bench` auto-offloads to a connected default Lab runner, where local --shared-state/--artifact-root paths will fail. Pass --local to force local (hot) execution against local checkouts and a local WP Codebox, or --runner/--lab-only to route to a runner with the paths present.',
    }] : []),
    ...(options.local ? [{
      code: 'forced_local_execution',
      message: '--local forces hot local execution (--force-hot --allow-local-hot); the bench will not offload to a Lab runner even if one is connected.',
    }] : []),
    ...(options.allowLocalFallback ? [{
      code: 'local_fallback_allowed',
      message: '--allow-local-fallback permits Lab routing to fall back to local execution if the runner allows it.',
    }] : []),
    ...(options.allowDirtyLabWorkspace ? [{
      code: 'dirty_lab_workspace_allowed',
      message: '--allow-dirty-lab-workspace permits reusing or overwriting a dirty Lab workspace.',
    }] : []),
  ];
}

// Surface corpus pin drift instead of letting `fixture_count_matches_canonical`
// be a silently-ignored boolean. A discovered count below the pin means fixtures
// went missing (or the wrong root was passed); above the pin means the corpus
// grew and CANONICAL_FIXTURE_COUNT needs an intentional bump.
function buildCanonicalDriftWarnings(fixtureCount, fixtureRoot) {
  if (fixtureCount === CANONICAL_FIXTURE_COUNT) {
    return [];
  }
  return [{
    code: 'canonical_fixture_count_drift',
    message: `Discovered ${fixtureCount} top-level fixture director${fixtureCount === 1 ? 'y' : 'ies'} in ${fixtureRoot}, but CANONICAL_FIXTURE_COUNT is ${CANONICAL_FIXTURE_COUNT}. ${fixtureCount > CANONICAL_FIXTURE_COUNT ? 'The corpus grew; bump CANONICAL_FIXTURE_COUNT after confirming the new fixtures are intended.' : 'Fixtures are missing or the wrong fixture root was passed; restore the corpus or correct --fixture-root.'}`,
  }];
}

export const CODE_FRESHNESS_SCHEMA = 'static-site-importer/fixture-matrix-code-freshness/v1';

// Roles checked for git freshness. Stale (behind/diverged) overrides risk
// producing phantom findings from code that no longer reflects upstream.
function freshnessTargets(options) {
  const targets = [];
  if (options.blocksEnginePhpTransformerPath) {
    targets.push({ role: 'blocks_engine_php_transformer_path', path: options.blocksEnginePhpTransformerPath });
  }
  if (options.blocksEngine && options.blocksEngine !== options.blocksEnginePhpTransformerPath) {
    targets.push({ role: 'blocks_engine', path: options.blocksEngine });
  }
  if (options.staticSiteImporter) {
    targets.push({ role: 'static_site_importer', path: options.staticSiteImporter });
  }
  return targets;
}

export function buildCodeFreshness(options, gitRunner = defaultGitRunner) {
  const paths = {};
  for (const target of freshnessTargets(options)) {
    paths[target.role] = resolvePathFreshness(target.role, target.path, gitRunner);
  }
  const stale = Object.values(paths).filter((entry) => entry.stale);
  return {
    schema: CODE_FRESHNESS_SCHEMA,
    would_block: stale.length > 0,
    stale_overrides: stale.map((entry) => entry.role),
    paths,
  };
}

export function resolvePathFreshness(role, targetPath, gitRunner = defaultGitRunner) {
  const resolved = path.resolve(targetPath);
  const base = {
    role,
    path: resolved,
    in_git_repo: false,
    branch: '',
    upstream: null,
    behind: 0,
    ahead: 0,
    dirty: false,
    commit: '',
    status: 'not_git',
    stale: false,
  };

  if (!fs.existsSync(resolved)) {
    return { ...base, status: 'missing' };
  }

  const inside = gitText(resolved, ['rev-parse', '--is-inside-work-tree'], gitRunner);
  if (inside.status !== 0 || inside.stdout !== 'true') {
    return base;
  }

  base.in_git_repo = true;
  base.branch = gitText(resolved, ['rev-parse', '--abbrev-ref', 'HEAD'], gitRunner).stdout;
  base.commit = gitText(resolved, ['rev-parse', 'HEAD'], gitRunner).stdout;
  base.dirty = gitText(resolved, ['status', '--porcelain'], gitRunner).stdout !== '';

  const upstream = gitText(resolved, ['rev-parse', '--abbrev-ref', '--symbolic-full-name', '@{upstream}'], gitRunner);
  if (upstream.status !== 0 || !upstream.stdout) {
    base.status = base.branch === 'HEAD' ? 'detached' : 'no_upstream';
    return base;
  }

  base.upstream = upstream.stdout;
  const counts = gitText(resolved, ['rev-list', '--left-right', '--count', `${upstream.stdout}...HEAD`], gitRunner);
  if (counts.status === 0 && /^\d+\s+\d+$/.test(counts.stdout)) {
    const [behind, ahead] = counts.stdout.split(/\s+/).map(Number);
    base.behind = behind;
    base.ahead = ahead;
  }

  base.status = freshnessStatus(base);
  // Behind or diverged means the override no longer matches upstream — block.
  base.stale = base.behind > 0;
  return base;
}

function freshnessStatus(entry) {
  if (entry.behind > 0 && entry.ahead > 0) {
    return 'diverged';
  }
  if (entry.behind > 0) {
    return 'behind';
  }
  if (entry.ahead > 0) {
    return 'ahead';
  }
  return 'fresh';
}

function buildFreshnessWarnings(codeFreshness, options) {
  const warnings = [];
  for (const role of codeFreshness.stale_overrides) {
    const entry = codeFreshness.paths[role];
    warnings.push({
      code: 'stale_override',
      message: `${role} (${entry.path}) is ${entry.status} vs ${entry.upstream} (behind ${entry.behind}, ahead ${entry.ahead}); findings may be phantom from stale code. Refresh it or pass --allow-stale-override to proceed.`,
    });
  }
  if (codeFreshness.would_block && options.allowStaleOverride) {
    warnings.push({
      code: 'stale_override_allowed',
      message: '--allow-stale-override permits running against a stale/diverged override; findings may not reproduce on upstream.',
    });
  }
  return warnings;
}

function resolveTransformerCommit(codeFreshness) {
  const entry = codeFreshness?.paths?.blocks_engine_php_transformer_path || codeFreshness?.paths?.blocks_engine;
  return entry?.commit || '';
}

function freshnessGuardBanner(codeFreshness, options) {
  const lines = [
    '',
    '================================================================',
    options.allowStaleOverride
      ? 'WARNING: running against a STALE/diverged override (--allow-stale-override)'
      : 'REFUSING TO RUN: a code override is STALE/diverged vs upstream',
    '================================================================',
  ];
  for (const role of codeFreshness.stale_overrides) {
    const entry = codeFreshness.paths[role];
    lines.push(`  - ${role}: ${entry.path}`);
    lines.push(`      branch ${entry.branch} is ${entry.status} vs ${entry.upstream} (behind ${entry.behind}, ahead ${entry.ahead}, ${entry.dirty ? 'dirty' : 'clean'})`);
    lines.push(`      commit ${entry.commit}`);
  }
  lines.push('Findings produced against stale code may not reproduce on upstream (phantom findings).');
  if (options.allowStaleOverride) {
    lines.push('Proceeding because --allow-stale-override was passed.');
  } else {
    lines.push('Refresh the checkout(s) to upstream, or pass --allow-stale-override to proceed anyway.');
  }
  lines.push('================================================================', '');
  return `${lines.join('\n')}\n`;
}

function defaultGitRunner(cwd, args) {
  const result = spawnSync('git', ['-C', cwd, ...args], { encoding: 'utf8' });
  return {
    status: result.error ? 1 : (result.status ?? 1),
    stdout: (result.stdout || '').trim(),
    stderr: (result.stderr || '').trim(),
  };
}

function gitText(cwd, args, gitRunner) {
  try {
    return gitRunner(cwd, args);
  } catch {
    return { status: 1, stdout: '', stderr: '' };
  }
}

function buildSteps(options, settings) {
  const steps = [];
  if (!options.skipInstall) {
    steps.push({
      label: 'Refresh installed SSI fixture matrix rig',
      command: options.homeboyBin,
      args: withCommonRouting(['rig', 'install', packageRoot, '--id', RIG_ID, '--reinstall'], options),
    });
  }
  if (!options.skipSync) {
    steps.push({
      label: 'Sync/materialize rig components',
      command: options.homeboyBin,
      args: withCommonRouting(['rig', 'sync', RIG_ID], options),
    });
  }

  const benchArgs = [
    'bench',
    '--rig', RIG_ID,
    '--profile', 'fixture-matrix',
    '--iterations', '1',
    '--path', options.staticSiteImporter,
    '--shared-state', options.sharedState,
    '--run-id', options.runId,
    '--output', options.output,
    '--json',
    '--setting', `static_site_importer_fixture_matrix_namespace=${options.namespace}`,
    ...Object.entries(settings).flatMap(([key, value]) => ['--setting', `bench_env.${key}=${value}`]),
  ];
  if (options.artifactRoot) {
    benchArgs.push('--artifact-root', options.artifactRoot);
  }
  const routedBenchArgs = withCommonRouting(benchArgs, options);
  if (options.passthrough.length > 0) {
    routedBenchArgs.push('--', ...options.passthrough);
  }
  steps.push({
    label: 'Run SSI fixture matrix bench through Homeboy/Lab/WP Codebox',
    command: options.homeboyBin,
    args: routedBenchArgs,
  });

  return steps;
}

function withCommonRouting(args, options) {
  const routed = [...args];
  // Force local (hot) execution. `homeboy bench` auto-offloads to a default Lab
  // runner whenever one is connected, even with no --runner flag. The offload
  // translates component/checkout paths into the remote workspace but passes
  // --shared-state / --artifact-root through verbatim, so a local absolute path
  // (e.g. /private/tmp/... or /Users/...) fails on the Linux runner with
  // "Permission denied" / "No such file". --force-hot --allow-local-hot together
  // keep the run on this machine so the local checkouts, fixture root, and
  // shared-state/artifact-root paths all resolve. This is the only way to run
  // the matrix against local-only paths and a local WP Codebox.
  if (options.local) {
    routed.push('--force-hot', '--allow-local-hot');
  }
  if (options.runner) {
    routed.push('--runner', options.runner);
  }
  if (options.labOnly) {
    routed.push('--lab-only');
  }
  if (options.allowLocalFallback) {
    routed.push('--allow-local-fallback');
  }
  if (options.detachAfterHandoff) {
    routed.push('--detach-after-handoff');
  }
  if (options.allowDirtyLabWorkspace) {
    routed.push('--allow-dirty-lab-workspace');
  }
  return routed;
}

function runCommand(step) {
  const status = runStep(step);
  if (status !== 0) {
    throw new Error(`${step.label} failed with exit ${status}`);
  }
}

// Run a step and return its exit status without throwing, so callers can decide
// whether a non-zero exit is fatal (setup) or an expected outcome (bench gate).
function runStep(step) {
  process.stderr.write(`\n# ${step.label}\n${shellCommand(step)}\n`);
  const result = spawnSync(step.command, step.args, { stdio: 'inherit' });
  return result.status;
}

// A bench gate-FAIL exits non-zero but still writes a parseable result payload
// to --output. Distinguish that (summarizable) from a genuine crash (no output
// or unparseable) so the operator always gets the summary on a failing run.
// On crash, preserve the historical throw/error behavior.
export function summarizeBenchRun({ plan, benchStatus, benchLabel = 'bench step' }) {
  if (benchStatus !== 0 && !benchProducedResult(plan.output_file)) {
    throw new Error(`${benchLabel} failed with exit ${benchStatus}`);
  }
  const gateFailed = benchStatus !== 0;
  return {
    gateFailed,
    summary: summarizeRun(plan, { status: gateFailed ? 'failed' : 'passed' }),
  };
}

// The bench RAN if --output exists, parses, and carries a result payload
// (a `result_summary` somewhere in the tree). That presence is the signal the
// gate evaluated a real run rather than the process crashing before writing
// results.
function benchProducedResult(outputFile) {
  const output = readJson(outputFile);
  if (!output || typeof output !== 'object') {
    return false;
  }
  return findFirstKey(output, 'result_summary') !== undefined;
}

export function summarizeRun(plan, { status } = {}) {
  const output = readJson(plan.output_file);
  const resultSummary = findFirstKey(output, 'result_summary') || {};
  const artifacts = findFirstKey(output, 'artifacts') || findFirstKey(output, 'artifact_refs') || {};
  const failedFixtureCount = Number(resultSummary.failed || 0);
  return {
    schema: 'static-site-importer/fixture-matrix-operator-summary/v1',
    // Explicit gate outcome; falls back to the failed count when not provided
    // so the field is always present on success and failure alike.
    status: status || (failedFixtureCount > 0 ? 'failed' : 'passed'),
    mode: plan.mode,
    run_id: findFirstKey(output, 'run_id') || plan.run_id,
    code_freshness: plan.code_freshness || null,
    transformer_commit: plan.transformer_commit || resolveTransformerCommit(plan.code_freshness),
    fixture_count: Number(findFirstKey(output, 'fixture_count') || plan.fixture_count || 0),
    passed_fixture_count: Number(resultSummary.succeeded || resultSummary.passed || 0),
    failed_fixture_count: failedFixtureCount,
    finding_count: Number(resultSummary.finding_count || 0),
    top_buckets: topObjectCounts(resultSummary.buckets || resultSummary.groups || {}),
    top_kinds: topObjectCounts(resultSummary.kinds || {}),
    top_pattern_families: normalizeSummaryRows(resultSummary.top_pattern_families),
    fixture_exemplars: normalizeSummaryRows(resultSummary.fixture_exemplars),
    diagnostic_blind_spots: normalizeSummaryRows(resultSummary.diagnostic_blind_spots),
    artifact_urls: collectArtifactUrls(artifacts),
    output_file: plan.output_file,
  };
}

function parseArgs(args) {
  const options = { passthrough: [] };
  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    if (arg === '--') {
      options.passthrough = args.slice(index + 1);
      break;
    }
    if (arg === '--help' || arg === '-h') {
      options.help = true;
      continue;
    }
    if (arg.startsWith('--no-')) {
      options[camelCase(arg.slice(5))] = false;
      continue;
    }
    if (arg.startsWith('--')) {
      const [rawKey, rawValue] = arg.slice(2).split('=');
      const key = camelCase(rawKey);
      const booleanKeys = new Set(['dryRun', 'skipInstall', 'skipSync', 'labOnly', 'local', 'allowLocalFallback', 'detachAfterHandoff', 'allowDirtyLabWorkspace', 'allowStaleOverride', 'visualParityGate', 'liveWpParity']);
      if (booleanKeys.has(key)) {
        options[key] = true;
        continue;
      }
      const value = rawValue === undefined ? args[index + 1] : rawValue;
      if (rawValue === undefined) {
        index += 1;
      }
      options[key] = value;
      continue;
    }
  }
  return options;
}

function countTopLevelFixtureDirectories(fixtureRoot) {
  try {
    return fs.readdirSync(fixtureRoot, { withFileTypes: true })
      .filter((entry) => entry.isDirectory() && !entry.name.startsWith('.'))
      .length;
  } catch {
    return 0;
  }
}

function readJson(file) {
  try {
    return JSON.parse(fs.readFileSync(file, 'utf8'));
  } catch {
    return null;
  }
}

function findFirstKey(value, key) {
  if (!value || typeof value !== 'object') {
    return undefined;
  }
  if (Object.prototype.hasOwnProperty.call(value, key)) {
    return value[key];
  }
  for (const child of Object.values(value)) {
    const found = findFirstKey(child, key);
    if (found !== undefined) {
      return found;
    }
  }
  return undefined;
}

function topObjectCounts(value, limit = 10) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return [];
  }
  return Object.entries(value)
    .map(([key, count]) => ({ key, count: Number(count) }))
    .filter((row) => Number.isFinite(row.count))
    .sort((a, b) => b.count - a.count || a.key.localeCompare(b.key))
    .slice(0, limit);
}

function normalizeSummaryRows(value, limit = 10) {
  return Array.isArray(value) ? value.slice(0, limit) : [];
}

function collectArtifactUrls(value) {
  const urls = [];
  collectUrls(value, urls);
  return urls;
}

function collectUrls(value, urls) {
  if (!value || typeof value !== 'object') {
    return;
  }
  for (const child of Object.values(value)) {
    if (typeof child === 'string' && /^(https:\/\/|gh:|homeboy-runs:|artifact:|run:)/.test(child)) {
      urls.push(child);
    } else {
      collectUrls(child, urls);
    }
  }
}

function shellCommand(step) {
  return [step.command, ...step.args].map(shellQuote).join(' ');
}

function shellQuote(value) {
  return /^[A-Za-z0-9_./:=@+-]+$/.test(value) ? value : `'${value.replaceAll("'", "'\\''")}'`;
}

function camelCase(value) {
  return value.replace(/-([a-z])/g, (_match, letter) => letter.toUpperCase());
}

function timestamp() {
  return new Date().toISOString().replace(/[-:]/g, '').replace(/\..+/, 'Z');
}

function sanitizePathSegment(value) {
  return String(value).replace(/[^A-Za-z0-9_.-]/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '') || 'run';
}

function printHelp() {
  process.stdout.write(`Usage: node tools/run-fixture-matrix.mjs --runner <id> --static-site-importer <path> --blocks-engine <path> [options] [-- <bench args>...]\n\nRuns the canonical Static Site Importer fixture matrix through Homeboy/Lab/WP Codebox.\n\nOptions:\n  --static-site-importer <path>       Static Site Importer checkout/plugin path. Required.\n  --blocks-engine <path>              Blocks Engine checkout. Defaults fixture root and PHP transformer override.\n  --fixture-root <path>               Fixture corpus. Defaults to <blocks-engine>/fixtures/websites.\n  --blocks-engine-php-transformer-path <path>\n                                      Override transformer package/repo path. Defaults to --blocks-engine.\n  --runner <id>                       Homeboy Lab runner, for example homeboy-lab.\n  --local                             Force hot local execution (--force-hot --allow-local-hot). Use this to run against local checkouts, a local fixture root, and a local WP Codebox; without it, homeboy bench auto-offloads to a connected default Lab runner where local --shared-state/--artifact-root paths fail.\n  --mode <development-override|release-proof>\n                                      Labels output; default is development-override when transformer override is used.\n  --run-id <id>                       Stable proof label. Defaults to ssi-matrix-<mode>-<timestamp>.\n  --shared-state <dir>                Shared Homeboy bench state directory.\n  --artifact-root <dir>               Homeboy artifact root.\n  --output <file>                     Structured Homeboy bench output file.\n  --batch-size <n>                    SSI fixture matrix WP Codebox batch size.\n  --concurrency <n>                   Parallel WP Codebox sandbox batches. Defaults to 4, hard-capped at 16.\n  --wordpress-version <version>       WP Codebox WordPress version.\n  --wp-codebox-bin <path>             WP Codebox CLI path.\n  --allow-stale-override              Proceed even when an override checkout is behind/diverged vs upstream.\n  --no-editor-validation              Skip the wordpress.editor-validate-blocks step (slow, launches a browser per site). Findings/native-rate still produced.\n  --no-visual-parity                  Skip the wordpress.visual-compare render/diff step.\n  --visual-parity-gate                Make pixel mismatch over the threshold a HARD gate. Default: capture-only.\n  --pixel-threshold <ratio>           Max mismatch ratio (mismatch_pixels/total_pixels) before gating. Default 0.1.\n  --live-wp-parity                    Opt-in: capture each imported candidate's rendered DOM (deterministic wordpress.capture-html) and score it against the source with the blocks-engine live-wp-parity comparator (live-WP score + render-free proxy score + delta). Default: off (render-free static gate stays primary).\n  --min-native-rate <ratio>           Opt-in: fail fixtures whose native_conversion_rate is below this ratio (0-1, or a percentage like 80). Default: off (metrics only).\n  --class <fixture-class>             Run only the given manifest class lane (e.g. marketing/static). Default: all classes.\n  --tag <tag>                         Run only fixtures whose manifest tags include this tag. Default: all fixtures.\n  --lab-only                          Require Lab routing.\n  --allow-local-fallback              Allow selected Lab runner local fallback.\n  --allow-dirty-lab-workspace         Allow runner workspace overwrite.\n  --detach-after-handoff              Return after remote runner accepts the job.\n  --skip-install                      Skip homeboy rig install --reinstall.\n  --skip-sync                         Skip homeboy rig sync.\n  --dry-run                           Print the composed plan without running it.\n\nAny args after -- are passed through to the lower-level bench runner.\n`);
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  await main();
}
