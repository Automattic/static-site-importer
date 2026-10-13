import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import {
  CAPTURE_PRODUCER_ENV, LoopBlocker, WORKFLOW_ID, WORKFLOW_SCHEMA,
  buildUrlProofWorkflow, captureCommands, codeboxArgs, codeboxRecipe, compareResult, composeRepository, evaluateLocalEvidence,
  evaluationVerdict, expandTemplate, importRequest, locateCapture, orderRepairBranches, parseArgs, readImportState, repairBranch,
  resolveCaptureProducer, routeFindings, sourceIdentity, writeRepairEvidence,
} from './url-loop-controller.mjs';

const url = 'https://example.com/';
const taskUrl = 'https://github.com/example/tracker/issues/1';
const tmp = (prefix) => fs.mkdtempSync(path.join(os.tmpdir(), prefix));
// A neutral stand-in: the capture producer is operator configuration.
const CAPTURE_REPO = 'capture-tool';
const producer = (root, properties = {}) => ({
  repository: CAPTURE_REPO, checkout: path.join(root, 'capture-tool'), remote: 'https://example.com/capture-tool.git', base: 'main',
  build: [['npm', 'ci'], ['npm', 'run', 'build']],
  capture: ['node', '{checkout}/cli.js', '{url}', '--output', '{output}', '--route-limit', '12'],
  compare: ['node', '{checkout}/cli.js', 'compare', '{capture}', '--stage', '{stage}'],
  compare_candidate: ['--candidate', '{candidate}'],
  ...properties,
});
function build(root, properties = {}) {
  return buildUrlProofWorkflow({ url, workspace: root, outputRoot: path.join(root, 'output'), blocksEngine: path.join(root, 'be'), wpCodeboxBin: path.join(root, 'codebox'), captureProducer: JSON.stringify(producer(root)), taskUrl, ...properties });
}
const step = (workflow, id) => workflow.steps.find((item) => item.id === id);
const argOf = (args, flag) => args[args.indexOf(flag) + 1];
const clean = { capture_repository: CAPTURE_REPO, capture_compare: { passed: true }, materialization_compare: { passed: true }, quality: { quality_pass: true, unsupported_fallback_count: 0, core_html_block_count: 0, freeform_block_count: 0, invalid_block_count: 0 } };

test('one URL yields a bounded three-repository repair loop', () => {
  const root = tmp('ssi-workflow-');
  const { workflow, context, inputs } = build(root);
  assert.equal(workflow.schema, WORKFLOW_SCHEMA);
  assert.equal(workflow.id, WORKFLOW_ID);
  assert.deepEqual(Object.keys(workflow.inputs), ['url'], 'the candidate is composed per iteration, not supplied');
  assert.deepEqual(inputs, { url });
  assert.equal(context.loop_id, sourceIdentity('https://example.com/#fragment'));
  assert.deepEqual(workflow.budget, { max_step_runs: 20, deadline: '10h' });
  assert.deepEqual(workflow.iterate, { until: { step: 'evaluate', output: '/verdict', equals: 'accepted' }, max_iterations: 3 });
  assert.deepEqual(workflow.steps.map((item) => item.id), ['evaluate', 'fix-ssi', 'fix-blocks-engine', 'fix-capture']);
  const evaluate = step(workflow, 'evaluate').exec;
  assert.equal(evaluate.program, process.execPath);
  assert.equal(argOf(evaluate.args, '--instance'), '{{instance}}');
  assert.equal(argOf(evaluate.args, '--iteration'), '{{iteration}}');
  assert.deepEqual(JSON.parse(argOf(evaluate.args, '--context')), context, 'the context is pinned inside the definition');
  assert.deepEqual(Object.keys(context.repositories), ['static-site-importer', 'blocks-engine', CAPTURE_REPO]);
  assert.equal(context.repositories['static-site-importer'].path, root);
  assert.equal(context.repositories['blocks-engine'].base, 'trunk');
  assert.deepEqual(context.repositories[CAPTURE_REPO], { role: 'capture', path: path.join(root, 'capture-tool'), remote: 'https://example.com/capture-tool.git', base: 'main' });
  assert.equal(context.capture_producer.repository, CAPTURE_REPO);
  assert.deepEqual(context.capture_producer.build, [['npm', 'ci'], ['npm', 'run', 'build']]);

  const expected = { 'fix-ssi': ['static-site-importer', 'npm test'], 'fix-blocks-engine': ['blocks-engine', 'composer --working-dir=php-transformer test'], 'fix-capture': [CAPTURE_REPO, 'npm test'] };
  for (const [id, [repo, verify]] of Object.entries(expected)) {
    const fix = step(workflow, id);
    assert.deepEqual(fix.needs, ['evaluate']);
    assert.deepEqual(fix.when, { step: 'evaluate', output: `/repairs/${repo}`, exists: true });
    assert.equal(fix.cook.repo, repo);
    assert.equal(fix.cook['task-url'], taskUrl);
    assert.deepEqual(fix.cook.verify, [verify]);
    assert.equal(fix.cook['draft-pr'], false);
    // Cook head and candidate discovery share one branch definition.
    assert.equal(fix.cook.head, repairBranch('{{instance}}', repo, '{{iteration}}'));
    assert.deepEqual(JSON.parse(fix.cook['provider-evidence']), { id: `${repo}-evaluation`, source: `{{steps.evaluate.output/repairs/${repo}/report_path}}` });
  }

  const custom = build(root, { instance: 'proof-r2', maxIterations: '2', maxStepRuns: '8', deadline: '3h', fixBackend: 'opencode', blocksEngineTaskUrl: 'https://github.com/Automattic/blocks-engine/issues/1', captureProducer: producer(root, { verify: ['npm test', 'npm run lint'], task_url: 'https://example.com/capture-tool/issues/9' }) });
  assert.equal(custom.context.loop_id, `${context.loop_id}-proof-r2`);
  assert.deepEqual(custom.workflow.budget, { max_step_runs: 8, deadline: '3h' });
  assert.equal(step(custom.workflow, 'fix-ssi').cook.backend, 'opencode');
  assert.equal(step(custom.workflow, 'fix-blocks-engine').cook['task-url'], 'https://github.com/Automattic/blocks-engine/issues/1');
  assert.deepEqual(step(custom.workflow, 'fix-capture').cook.verify, ['npm test', 'npm run lint']);
  assert.equal(step(custom.workflow, 'fix-capture').cook['task-url'], 'https://example.com/capture-tool/issues/9');
  assert.throws(() => build(root, { instance: '../invalid' }), /path-safe/);
  assert.throws(() => build(root, { maxIterations: 9 }), /max-iterations/);
  assert.throws(() => build(root, { maxStepRuns: 1 }), /max-step-runs/);
  assert.throws(() => build(root, { deadline: 'soon' }), /deadline/);
  assert.throws(() => buildUrlProofWorkflow({ url, workspace: root, wpCodeboxBin: root, captureProducer: producer(root), taskUrl }), /must be configured/);
  const previous = process.env.SSI_URL_LOOP_TASK_URL;
  try {
    delete process.env.SSI_URL_LOOP_TASK_URL;
    assert.throws(() => build(root, { taskUrl: undefined }), /task-url must name the tracker/, 'there is no default tracker');
    assert.equal(step(build(root, { taskUrl: undefined, ssiTaskUrl: 'https://example.com/ssi/1', blocksEngineTaskUrl: 'https://example.com/be/1', captureProducer: producer(root, { task_url: 'https://example.com/capture/1' }) }).workflow, 'fix-ssi').cook['task-url'], 'https://example.com/ssi/1', 'per-repository trackers alone suffice');
  } finally {
    if (previous !== undefined) process.env.SSI_URL_LOOP_TASK_URL = previous;
  }
});

test('the capture producer is validated operator configuration', () => {
  const root = tmp('ssi-producer-');
  const previous = process.env[CAPTURE_PRODUCER_ENV];
  try {
    delete process.env[CAPTURE_PRODUCER_ENV];
    assert.throws(() => resolveCaptureProducer({}), /capture_producer_missing/);
    process.env[CAPTURE_PRODUCER_ENV] = JSON.stringify(producer(root));
    assert.equal(resolveCaptureProducer({}).repository, CAPTURE_REPO, 'the runner environment configures it');
  } finally {
    if (previous === undefined) delete process.env[CAPTURE_PRODUCER_ENV]; else process.env[CAPTURE_PRODUCER_ENV] = previous;
  }
  assert.throws(() => resolveCaptureProducer({ captureProducer: '{' }), /expected a JSON object/);
  assert.throws(() => resolveCaptureProducer({ captureProducer: producer(root, { repository: 'blocks-engine' }) }), /capture repository/);
  assert.throws(() => resolveCaptureProducer({ captureProducer: producer(root, { remote: '' }) }), /required/);
  assert.throws(() => resolveCaptureProducer({ captureProducer: producer(root, { capture: ['node', 'cli.js', '{url}'] }) }), /must contain \{output\}/);
  assert.throws(() => resolveCaptureProducer({ captureProducer: producer(root, { compare_candidate: ['--candidate'] }) }), /must contain \{candidate\}/);
  assert.equal(resolveCaptureProducer({ captureProducer: producer(root) }).compare_report, '{capture}/compare/{stage}/report.json');
});

test('steps run with only the declared public toolchain environment', () => {
  const previous = process.env.PLAYWRIGHT_BROWSERS_PATH;
  try {
    process.env.PLAYWRIGHT_BROWSERS_PATH = '/declared/browser-cache';
    const { workflow, context } = build(tmp('ssi-runtime-context-'));
    assert.ok(workflow.env.public.includes('PATH'));
    assert.ok(workflow.env.public.includes('PLAYWRIGHT_BROWSERS_PATH'));
    assert.equal(workflow.env.secrets, undefined);
    assert.equal(context.runtime_toolchain.env.PLAYWRIGHT_BROWSERS_PATH, '/declared/browser-cache');
    assert.equal(Object.hasOwn(context.runtime_toolchain.env, 'PATH'), false);
  } finally {
    if (previous === undefined) delete process.env.PLAYWRIGHT_BROWSERS_PATH; else process.env.PLAYWRIGHT_BROWSERS_PATH = previous;
  }
});

test('each routing class reaches its owning repository', () => {
  const repairs = routeFindings({
    captureRepository: CAPTURE_REPO,
    captureFailures: [{ url: 'https://example.com/a' }],
    captureCompare: { passed: false, report_path: '/c.json' },
    packets: [{ owner: 'blocks-engine/php-transformer', type: 'core_html_block' }, { owner: 'static-site-importer', type: 'media' }],
    importFailures: [{ code: 'materialization_failed' }],
    materializationCompare: { passed: false, report_path: '/m.json' },
  });
  assert.deepEqual(repairs[CAPTURE_REPO].findings.map((item) => item.kind), ['capture_failure', 'capture_compare_failed']);
  assert.deepEqual(repairs['blocks-engine'].findings.map((item) => item.type), ['core_html_block']);
  assert.deepEqual(repairs['static-site-importer'].findings.map((item) => item.kind), ['finding_packet', 'import_failure', 'materialization_compare_failed']);
  assert.deepEqual(routeFindings({ captureRepository: CAPTURE_REPO, captureCompare: { passed: true }, materializationCompare: { passed: true } }), {});
});

test('the verdict needs complete evidence, passing comparisons and a clean quality result', () => {
  const accepted = evaluateLocalEvidence({ ...clean, capture_failures: [{ url: 'https://example.com/login' }] });
  assert.equal(accepted.verdict, 'accepted');
  assert.equal(accepted.acceptance.solved, true);
  assert.deepEqual(accepted.repairs, {}, 'an accepted candidate launches no repair, even for advisory capture failures');

  const html = evaluateLocalEvidence({ ...clean, quality: { ...clean.quality, quality_pass: false, core_html_block_count: 7 }, finding_packets: [{ owner: 'blocks-engine/php-transformer' }] });
  assert.equal(html.verdict, 'findings');
  assert.equal(html.loss_count, 7);
  assert.deepEqual(Object.keys(html.repairs), ['blocks-engine']);

  const drift = evaluateLocalEvidence({ ...clean, materialization_compare: { passed: false } });
  assert.deepEqual([drift.verdict, Object.keys(drift.repairs)], ['findings', ['static-site-importer']]);
  const capture = evaluateLocalEvidence({ ...clean, capture_compare: { passed: false } });
  assert.deepEqual([capture.verdict, Object.keys(capture.repairs)], ['findings', [CAPTURE_REPO]]);

  assert.deepEqual([evaluateLocalEvidence({}).verdict, evaluateLocalEvidence({}).blocker], ['blocked', 'evaluation_evidence_missing']);
  const unrouted = evaluateLocalEvidence({ ...clean, quality: { ...clean.quality, invalid_block_count: 1 } });
  assert.deepEqual([unrouted.verdict, unrouted.blocker], ['blocked', 'unrouted_failure'], 'failing evidence without an owner is not silently accepted or misrouted');
});

test('every routed repository gets a citable evidence file, and the step output stays compact', () => {
  const directory = tmp('ssi-repair-evidence-');
  const repairs = writeRepairEvidence(directory, routeFindings({ captureRepository: CAPTURE_REPO, captureCompare: { passed: false }, packets: [{ owner: 'blocks-engine/php-transformer' }] }), { url, components: { 'blocks-engine': { head_sha: 'b'.repeat(40) } } });
  for (const [repo, work] of Object.entries(repairs)) {
    assert.ok(work.report_path, `${repo} has a report path`);
    const written = JSON.parse(fs.readFileSync(work.report_path, 'utf8'));
    assert.equal(written.repository, repo);
    assert.equal(written.findings.length, work.findings.length);
    assert.equal(written.components['blocks-engine'].head_sha, 'b'.repeat(40));
  }
  const output = evaluationVerdict({ verdict: 'findings', repairs, components: { 'blocks-engine': { head_sha: 'b'.repeat(40), merged: [{ branch: 'url-loop/x/blocks-engine-i1', sha: 'c'.repeat(40) }] } }, quality: { quality_pass: false } }, '/e.json');
  assert.deepEqual(output.repairs['blocks-engine'], { findings: 1, report_path: repairs['blocks-engine'].report_path });
  assert.deepEqual(output.components['blocks-engine'].merged, ['url-loop/x/blocks-engine-i1']);
});

test('repair branches are discovered for one instance and repository in iteration order', () => {
  assert.equal(repairBranch('wf-1', 'blocks-engine', 2), 'url-loop/wf-1/blocks-engine-i2');
  assert.equal(repairBranch('wf-1', CAPTURE_REPO, 3), `url-loop/wf-1/${CAPTURE_REPO}-i3`);
  assert.throws(() => repairBranch('wf-1', '../other', 1), /repair_repository_invalid/);
  const refs = ['url-loop/wf-1/blocks-engine-i10', 'url-loop/wf-1/blocks-engine-i2', 'url-loop/wf-1/static-site-importer-i1', 'url-loop/wf-2/blocks-engine-i1', 'url-loop/wf-1/blocks-engine-ix'].map((branch, index) => ({ branch, sha: String(index).repeat(40) }));
  assert.deepEqual(orderRepairBranches(refs, 'wf-1', 'blocks-engine').map((ref) => ref.branch), ['url-loop/wf-1/blocks-engine-i2', 'url-loop/wf-1/blocks-engine-i10']);
});

function gitIn(cwd, ...args) {
  const result = spawnSync('git', ['-c', 'user.name=t', '-c', 'user.email=t@t', ...args], { cwd, encoding: 'utf8' });
  assert.equal(result.status, 0, result.stderr);
  return result.stdout.trim();
}

test('the candidate merges this instance\'s repair branches onto the base, and a conflict is a typed blocker', () => {
  const root = tmp('ssi-compose-');
  const remote = path.join(root, 'remote');
  gitIn(root, 'init', '--quiet', '-b', 'trunk', remote);
  fs.writeFileSync(path.join(remote, 'a.txt'), 'base\n');
  gitIn(remote, 'add', '.');
  gitIn(remote, 'commit', '--quiet', '-m', 'base');
  const base = gitIn(remote, 'rev-parse', 'HEAD');
  const branch = (name, file, content) => {
    gitIn(remote, 'checkout', '--quiet', '-b', name, base);
    fs.writeFileSync(path.join(remote, file), content);
    gitIn(remote, 'add', '.');
    gitIn(remote, 'commit', '--quiet', '-m', name);
    gitIn(remote, 'checkout', '--quiet', 'trunk');
  };
  branch(repairBranch('wf-1', 'blocks-engine', 1), 'b.txt', 'one\n');
  branch(repairBranch('wf-1', 'blocks-engine', 2), 'c.txt', 'two\n');
  branch(repairBranch('wf-other', 'blocks-engine', 1), 'd.txt', 'other instance\n');
  const source = path.join(root, 'source');
  gitIn(root, 'clone', '--quiet', remote, source);

  const composed = composeRepository({ repo: 'blocks-engine', source, remote, base: 'trunk', instance: 'wf-1', directory: path.join(root, 'eval', 'candidate', 'blocks-engine') });
  assert.equal(composed.base_sha, base);
  assert.deepEqual(composed.merged.map((item) => item.branch), [repairBranch('wf-1', 'blocks-engine', 1), repairBranch('wf-1', 'blocks-engine', 2)]);
  assert.ok(fs.existsSync(path.join(composed.path, 'b.txt')) && fs.existsSync(path.join(composed.path, 'c.txt')));
  assert.equal(fs.existsSync(path.join(composed.path, 'd.txt')), false, 'another instance\'s branches are not composed');

  branch(repairBranch('wf-2', 'blocks-engine', 1), 'a.txt', 'left\n');
  branch(repairBranch('wf-2', 'blocks-engine', 2), 'a.txt', 'right\n');
  assert.throws(
    () => composeRepository({ repo: 'blocks-engine', source, remote, base: 'trunk', instance: 'wf-2', directory: path.join(root, 'eval2', 'candidate', 'blocks-engine') }),
    (error) => error instanceof LoopBlocker && error.reason === `candidate_merge_conflict:blocks-engine:${repairBranch('wf-2', 'blocks-engine', 2)}`,
  );
});

test('the Codebox recipe drives SSI in stepped mode on a WordPress with WP-CLI and the form provider', () => {
  const recipe = codeboxRecipe({ pluginZip: '/p/ssi.zip', stage: '/s', wordpressVersion: '7.1', importSteps: 3 });
  assert.equal(recipe.runtime.wp, '7.1');
  assert.deepEqual(recipe.runtime.blueprint.extraLibraries, ['wp-cli']);
  assert.equal(recipe.runtime.blueprint.steps[0].pluginData.slug, 'jetpack');
  assert.deepEqual(recipe.inputs.extra_plugins, [{ source: '/p/ssi.zip', slug: 'static-site-importer', activate: true }]);
  assert.deepEqual(recipe.inputs.mounts, [{ type: 'directory', source: '/s', target: '/url-loop', mode: 'readwrite' }]);
  assert.equal(recipe.workflow.steps.length, 3);
  assert.match(recipe.workflow.steps[0].args[0], /^command=static-site-importer import --single-step --state=\/url-loop\/state\.json --request=\/url-loop\/request\.json --report=/);
  const args = codeboxArgs({ recipe: '/r.json', artifacts: '/a', port: 9488, timeoutMs: 3600000 });
  assert.equal(argOf(args, '--timeout'), '3600000', 'Codebox timeouts are milliseconds');
  assert.ok(args.includes('--preview-hold-blocking'));
  assert.equal(argOf(args, '--preview-port'), '9488');
  assert.deepEqual(importRequest('https://Example.com/x').source, { type: 'files', ref: 'request-bundle:website', entrypoint: 'index.html' });
  assert.equal(importRequest('https://example.com/').slug, 'url-loop-example-com');
});

test('producer commands expand per composed checkout, and the capture is found by its receipt', () => {
  const configured = resolveCaptureProducer({ captureProducer: producer('/r') });
  assert.deepEqual(expandTemplate(['{a}-{b}', '{missing}'], { a: 1, b: 'x' }), ['1-x', '{missing}']);
  const commands = captureCommands(configured, { checkout: '/c', url, output: '/o', capture: '/o/site', candidate: 'http://127.0.0.1:1/' });
  assert.deepEqual(commands.capture, ['node', '/c/cli.js', url, '--output', '/o', '--route-limit', '12']);
  assert.deepEqual(commands.compare('capture'), ['node', '/c/cli.js', 'compare', '/o/site', '--stage', 'capture']);
  assert.deepEqual(commands.compare('materialization'), ['node', '/c/cli.js', 'compare', '/o/site', '--stage', 'materialization', '--candidate', 'http://127.0.0.1:1/']);
  assert.equal(commands.report('materialization'), '/o/site/compare/materialization/report.json');

  const output = tmp('ssi-capture-');
  assert.equal(locateCapture(output), null, 'no receipt is no capture');
  const site = path.join(output, 'example.com');
  fs.mkdirSync(path.join(site, 'website'), { recursive: true });
  fs.writeFileSync(path.join(site, 'website', 'index.html'), '<html></html>');
  fs.writeFileSync(path.join(site, 'capture-receipt.json'), JSON.stringify({ schema: 'blocks-engine-capture/capture-receipt/v1', websiteRoot: 'website', summary: { routesCaptured: 11, routesFailed: 1 } }));
  const located = locateCapture(output);
  assert.equal(located.directory, site);
  assert.equal(located.website, path.join(site, 'website'));
  assert.deepEqual(located.failures, [{ routes_failed: 1, routes_captured: 11, receipt_path: path.join(site, 'capture-receipt.json') }]);
});

test('import state and compare reports become verdict evidence', () => {
  assert.equal(readImportState({}), null, 'a non-terminal import is not evidence');
  const success = readImportState({ terminal: { success: true, result: { import_report_summary: { quality_pass: false, core_html_block_count: 7, unsupported_fallback_count: 11, failure_reasons: ['core_html_block'] } } } });
  assert.deepEqual(success, { success: true, import_failures: [], quality: { quality_pass: false, failure_reasons: ['core_html_block'], unsupported_fallback_count: 11, core_html_block_count: 7, freeform_block_count: 0, invalid_block_count: 0 } });
  const failed = readImportState({ terminal: { success: false, error: { code: 'report_destination_not_ready', message: 'x' } } });
  assert.deepEqual(failed.import_failures, [{ code: 'report_destination_not_ready', message: 'x' }]);
  assert.equal(failed.quality.quality_pass, false);
  const directory = tmp('ssi-compare-');
  const report = path.join(directory, 'report.json');
  assert.equal(compareResult(report), null);
  fs.writeFileSync(report, JSON.stringify({ pass: false, status: 'failed', failed: [{ route: '/' }] }));
  assert.deepEqual(compareResult(report), { passed: false, status: 'failed', report_path: report, failed: [{ route: '/' }] });
});

test('repeated per-repository verify flags accumulate', () => {
  assert.deepEqual(parseArgs(['--url', url, '--ssi-verify', 'a', '--ssi-verify', 'b', '--max-iterations', '2']), { url, ssiVerify: ['a', 'b'], maxIterations: '2' });
});

test('Homeboy accepts the generated definition', (context) => {
  const probe = spawnSync('homeboy', ['workflow', 'validate', '--help'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    context.skip('installed Homeboy has no `workflow` command');
    return;
  }
  const root = tmp('ssi-workflow-validate-');
  const file = path.join(root, 'workflow.json');
  fs.writeFileSync(file, JSON.stringify(build(root).workflow));
  const result = spawnSync('homeboy', ['workflow', 'validate', file], { encoding: 'utf8' });
  assert.equal(result.status, 0, `${result.stderr}\n${result.stdout}`);
  const data = JSON.parse(result.stdout).data;
  assert.equal(data.id, WORKFLOW_ID);
  assert.deepEqual(data.steps.map((item) => [item.id, item.kind]), [['evaluate', 'exec'], ['fix-ssi', 'cook'], ['fix-blocks-engine', 'cook'], ['fix-capture', 'cook']]);
});
