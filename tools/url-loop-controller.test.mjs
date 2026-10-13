import assert from 'node:assert/strict';
import test from 'node:test';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { spawnSync } from 'node:child_process';
import { fileURLToPath } from 'node:url';
import { CAPTURE_ARTIFACT_SCHEMA, EVALUATION_ARTIFACT_SCHEMA, WORKFLOW_ID, WORKFLOW_SCHEMA, buildUrlProofWorkflow, evaluateMatrixSummary, evaluationVerdict, parseArgs, prepareTransformer, runCapture, runEvaluation, sourceIdentity, verifyCandidateWorkspace } from './url-loop-controller.mjs';

const url = 'https://example.com/';
const sha = 'a'.repeat(40);
const taskUrl = 'https://github.com/example/tracker/issues/1';
const provenance = { source_url_sha256: `sha256:${'1'.repeat(64)}`, captured_content_sha256: `sha256:${'2'.repeat(64)}`, capture_receipt_sha256: `sha256:${'3'.repeat(64)}` };
function build(root, properties = {}) {
  if (!fs.existsSync(path.join(root, 'composer.lock'))) fs.copyFileSync(new URL('../composer.lock', import.meta.url), path.join(root, 'composer.lock'));
  return buildUrlProofWorkflow({ url, workspace: root, outputRoot: path.join(root, 'output'), blocksEngine: root, wpCodeboxBin: path.join(root, 'codebox'), fixTaskUrl: taskUrl, ...properties });
}
function capture(root, properties = {}) {
  const handoffPath = path.join(root, 'output', 'capture-intake', 'url-loop-handoff.json');
  const handoff = {
    url, status: 'needs_evaluation', fixture: { id: 'website', fixture_root: path.join(root, 'fixtures') },
    capture_receipt: { schema: 'blocks-engine-capture/capture-receipt/v1', source: { url }, summary: { complete: true, routesDiscovered: 2, routesCaptured: 2, routesFailed: 0, routesSkipped: 0 } },
    provenance, identities: { dla: { version: 'v0.27.0' } }, failures: [], handoff_path: handoffPath,
    ...properties,
  };
  fs.mkdirSync(path.dirname(handoffPath), { recursive: true });
  fs.writeFileSync(handoffPath, JSON.stringify(handoff));
  return handoff;
}
const step = (workflow, id) => workflow.steps.find((item) => item.id === id);
const contextOf = (workflow) => JSON.parse(step(workflow, 'capture').exec.args[step(workflow, 'capture').exec.args.indexOf('--context') + 1]);

test('one URL yields a bounded homeboy/workflow/v1 proof: capture, evaluation gate, repair Cook, re-check', () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-workflow-'));
  const { workflow, context, inputs } = build(root);
  assert.equal(workflow.schema, WORKFLOW_SCHEMA);
  assert.equal(workflow.id, WORKFLOW_ID);
  assert.deepEqual(Object.keys(workflow.inputs), ['url', 'candidate_sha']);
  assert.deepEqual(inputs, { url });
  assert.equal(context.loop_id, sourceIdentity(url));
  assert.equal(context.loop_id, sourceIdentity('https://example.com/#fragment'));
  assert.deepEqual(workflow.budget, { max_step_runs: 12, deadline: '10h' });
  assert.deepEqual(workflow.iterate, { until: { step: 'evaluate', output: '/verdict', equals: 'accepted' }, max_iterations: 3 });
  assert.deepEqual(workflow.steps.map((item) => item.id), ['capture', 'evaluate', 'fix']);
  // Capture runs once; later iterations keep its carried output.
  assert.deepEqual(step(workflow, 'capture').when, { not: { step: 'capture', outcome: ['succeeded'] } });
  assert.equal(step(workflow, 'capture').exec.program, process.execPath);
  assert.equal(step(workflow, 'capture').exec.cwd, root);
  const evaluate = step(workflow, 'evaluate').exec.args;
  assert.deepEqual(step(workflow, 'evaluate').needs, ['capture']);
  assert.equal(evaluate[evaluate.indexOf('--capture') + 1], '{{steps.capture.output}}');
  assert.equal(evaluate[evaluate.indexOf('--candidate-sha') + 1], '{{inputs.candidate_sha}}');
  assert.equal(evaluate[evaluate.indexOf('--action') + 1], '{{instance}}-i{{iteration}}');
  const fix = step(workflow, 'fix');
  assert.deepEqual(fix.when, { step: 'evaluate', output: '/verdict', equals: 'findings' });
  assert.equal(fix.cook.repo, 'static-site-importer');
  assert.equal(fix.cook['task-url'], taskUrl);
  assert.deepEqual(fix.cook.verify, ['npm test']);
  assert.match(fix.cook.prompt, /\{\{steps\.evaluate\.output\/report_path\}\}/);
  // The context is pinned inside the definition, so it is part of its digest.
  assert.deepEqual(contextOf(workflow), context);
  assert.equal(context.candidate_sha, undefined, 'the candidate is an instance input, not definition state');
  const proof = build(root, { instance: 'proof-r2', maxIterations: '2', maxStepRuns: '4', deadline: '3h', fixRepo: 'scratch', fixVerify: ['test -f FIXED', 'true'], fixBackend: 'opencode', fixPrompt: 'Fix {{inputs.url}}' });
  assert.equal(proof.context.source_id, context.loop_id);
  assert.equal(proof.context.loop_id, `${context.loop_id}-proof-r2`);
  assert.deepEqual(proof.workflow.budget, { max_step_runs: 4, deadline: '3h' });
  assert.equal(proof.workflow.iterate.max_iterations, 2);
  assert.deepEqual(step(proof.workflow, 'fix').cook, { repo: 'scratch', 'task-url': taskUrl, placement: 'local', backend: 'opencode', prompt: 'Fix {{inputs.url}}', verify: ['test -f FIXED', 'true'] });
  assert.throws(() => build(root, { instance: '../invalid' }), /path-safe/);
  assert.throws(() => build(root, { maxIterations: 9 }), /max-iterations/);
  assert.throws(() => build(root, { maxStepRuns: 1 }), /max-step-runs/);
  assert.throws(() => build(root, { deadline: 'soon' }), /deadline/);
  assert.throws(() => buildUrlProofWorkflow({ url, workspace: root, blocksEngine: root, wpCodeboxBin: root }), /fix-task-url/);
});

test('steps run with only the declared public toolchain environment', () => {
  const keys = ['PLAYWRIGHT_BROWSERS_PATH', 'PLAYWRIGHT_HOST_PLATFORM_OVERRIDE'];
  const previous = keys.map((key) => process.env[key]);
  try {
    process.env.PLAYWRIGHT_BROWSERS_PATH = '/declared/browser-cache';
    process.env.PLAYWRIGHT_HOST_PLATFORM_OVERRIDE = 'ubuntu24.04-x64';
    const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-runtime-context-'));
    const { workflow, context } = build(root);
    assert.ok(workflow.env.public.includes('PATH'));
    assert.ok(workflow.env.public.includes('PLAYWRIGHT_BROWSERS_PATH'));
    assert.equal(workflow.env.secrets, undefined);
    assert.equal(workflow.env.public.includes('OPENAI_API_KEY'), false);
    const retained = contextOf(workflow).runtime_toolchain;
    assert.deepEqual(retained, context.runtime_toolchain);
    assert.equal(retained.node, process.execPath);
    assert.equal(retained.npx, path.join(path.dirname(process.execPath), 'npx'));
    assert.equal(retained.env.PLAYWRIGHT_BROWSERS_PATH, '/declared/browser-cache');
    assert.equal(retained.env.PLAYWRIGHT_HOST_PLATFORM_OVERRIDE, 'ubuntu24.04-x64');
    assert.equal(Object.hasOwn(retained.env, 'OPENAI_API_KEY'), false);
    assert.equal(Object.hasOwn(retained.env, 'PATH'), false);
  } finally {
    keys.forEach((key, index) => previous[index] === undefined ? delete process.env[key] : process.env[key] = previous[index]);
  }
});

test('complete DLA capture is retained once per proof root and reused by later instances', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-workflow-capture-'));
  const { context } = build(root);
  let count = 0;
  const first = await runCapture(context, url, { capture: async (request) => { ++count; assert.deepEqual(request.runtimeToolchain, context.runtime_toolchain); return capture(root); } });
  const second = await runCapture(context, url, { capture: async () => { ++count; throw new Error('must reuse the retained capture'); } });
  assert.equal(count, 1);
  assert.deepEqual(second, first);
  assert.equal(first.schema, CAPTURE_ARTIFACT_SCHEMA);
  assert.equal(first.routes, 2);
  assert.equal(first.provenance.captured_content_sha256, provenance.captured_content_sha256);
  await assert.rejects(runCapture(context, 'https://other.example/', {}), /workflow_context_url_mismatch/);
  const blockedRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-workflow-capture-blocked-'));
  const blocked = build(blockedRoot).context;
  await assert.rejects(runCapture(blocked, url, { capture: async () => capture(blockedRoot, { status: 'blocked', failures: [{ stage: 'capture', reason: 'child_nonzero_exit' }] }) }), /capture_blocked:.*child_nonzero_exit/);
  // A retained blocked capture stays a blocker; it is never silently replaced.
  await assert.rejects(runCapture(blocked, url, { capture: async () => capture(blockedRoot) }), /capture_blocked/);
});

test('matrix findings become the `findings` verdict that gates repair, and never become solved', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-workflow-evaluate-'));
  const { context } = build(root);
  const artifact = await runCapture(context, url, { capture: async () => capture(root) });
  const summary = { schema: 'static-site-importer/fixture-matrix-operator-summary/v1', status: 'failed', matrix_evidence_readiness: { schema: 'static-site-importer/fixture-matrix-runtime-evidence-summary/v1', status: 'incomplete', fixtures: [{ fixture_id: 'website', readiness: 'runtime_evidence_incomplete', missing: ['transformer_reference'] }] }, gate_failure_reasons: [{ category: 'visual_mismatch', fixture_id: 'website', source_path: 'website/index.html' }], run_refs: { homeboy_run_id: 'matrix-1' } };
  const actual = await runEvaluation({ context, url, capture: artifact, candidateSha: sha, actionId: 'wf-ssi-url-proof-abc-i1' }, {
    matrixModule: { buildFixtureMatrixRunPlan: () => ({ output_file: 'result.json', steps: [] }), summarizeBenchRun: () => ({ summary }) },
    spawn: () => ({ status: 1 }),
  });
  assert.equal(actual.evaluation.schema, EVALUATION_ARTIFACT_SCHEMA);
  assert.equal(actual.evaluation.status, 'needs_repair');
  assert.equal(actual.evaluation.acceptance.solved, false);
  assert.ok(actual.evaluation.missing.includes('transformer_reference'));
  assert.equal(actual.evaluation.findings[0].category, 'visual_mismatch');
  assert.equal(actual.evaluation.source_url_sha256, provenance.source_url_sha256);
  assert.equal(actual.report_path, path.join(context.root, 'evaluations', sha, 'wf-ssi-url-proof-abc-i1', 'evaluation.json'));
  const saved = JSON.parse(fs.readFileSync(actual.report_path));
  assert.equal(saved.candidate_sha, sha);
  const verdict = evaluationVerdict(actual.evaluation, actual.report_path);
  assert.equal(verdict.verdict, 'findings');
  assert.equal(verdict.findings, 1);
  assert.equal(verdict.report_path, actual.report_path);
  await assert.rejects(runEvaluation({ context, url, capture: artifact, candidateSha: sha, actionId: 'wf-ssi-url-proof-abc-i1' }, {}), /matrix_output_not_fresh/);
  await assert.rejects(runEvaluation({ context, url, capture: artifact, candidateSha: sha, actionId: '../escape' }, {}), /evaluation_action_identity_invalid/);
  await assert.rejects(runEvaluation({ context, url, capture: { ...artifact, provenance: { ...provenance, captured_content_sha256: 'different' } }, candidateSha: sha, actionId: 'i2' }, {}), /capture_handoff_identity_mismatch/);
});

test('absent matrix evidence is a typed blocker; zero fallback never implies solved', () => {
  const artifact = { fixture_id: 'website', routes: 1, provenance };
  const blocked = evaluateMatrixSummary({ summary: null, capture: artifact, candidateSha: sha, runId: 'r1' });
  assert.equal(blocked.status, 'blocked');
  assert.equal(evaluationVerdict(blocked, 'report.json').verdict, 'blocked');
  const evaluated = evaluateMatrixSummary({ summary: { status: 'passed', fallback_count: 0, matrix_evidence_readiness: { fixtures: [{ fixture_id: 'website', readiness: 'verified', missing: [] }] } }, benchStatus: 0, capture: artifact, candidateSha: sha, runId: 'r1' });
  assert.equal(evaluated.status, 'needs_evidence');
  assert.equal(evaluationVerdict(evaluated, 'report.json').verdict, 'needs_evidence');
  assert.ok(evaluated.missing.includes('visual_viewport_390_missing'));
  assert.equal(evaluated.acceptance.solved, false);
  assert.equal(evaluationVerdict({ ...evaluated, acceptance: { solved: true } }, 'report.json').verdict, 'accepted');
});

test('a new candidate SHA is a new workflow input over the same definition and retained capture', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-pinned-replay-'));
  const { workflow, context } = build(root);
  assert.deepEqual(build(root).workflow, workflow, 'the definition does not depend on the candidate');
  const artifact = await runCapture(context, url, { capture: async () => capture(root, {
    capture_receipt: { schema: 'blocks-engine-capture/capture-receipt/v1', source: { url }, summary: { complete: true, routesDiscovered: 8, routesCaptured: 8, routesFailed: 0, routesSkipped: 0 } },
  }) });
  const retained = fs.readFileSync(artifact.handoff_path);
  const locked = JSON.parse(fs.readFileSync(path.join(root, 'composer.lock'))).packages.find((item) => item.name === 'automattic/blocks-engine-php-transformer');
  for (const candidate of [sha, 'b'.repeat(40)]) {
    const result = await runEvaluation({ context, url, capture: artifact, candidateSha: candidate, actionId: 'i1' }, {
      matrixModule: {
        buildFixtureMatrixRunPlan: (input) => {
          assert.equal(input.mode, 'release-proof');
          assert.equal(input.blocksEnginePhpTransformerPath, '');
          assert.equal(input.surfaceCoverage, 7);
          assert.equal(input.fixtureRoot, artifact.fixture_root);
          return { steps: [] };
        },
        summarizeBenchRun: () => ({ summary: { matrix_evidence_readiness: { fixtures: [] } } }),
      },
      spawn: (_command, args) => {
        assert.ok(args.includes('release-proof'));
        assert.ok(!args.includes('--blocks-engine-php-transformer-path'));
        return { status: 0 };
      },
    });
    assert.deepEqual(result.evaluation.transformer, { package: locked.name, version: locked.version, reference: locked.source.reference });
    assert.equal(result.evaluation.routes, 8);
    assert.equal(result.evaluation.candidate_sha, candidate);
    assert.equal(result.evaluation.captured_content_sha256, provenance.captured_content_sha256);
    assert.deepEqual(fs.readFileSync(artifact.handoff_path), retained);
  }
});

test('explicit installed compiler snapshot proves real PHP exports and refuses an incompatible overlay before activation', async () => {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-explicit-compiler-'));
  const { context } = build(root);
  const packagePath = fileURLToPath(new URL('../vendor/automattic/blocks-engine-php-transformer/', import.meta.url));
  const compatible = prepareTransformer({ ...context, transformer_path: packagePath }, path.join(root, 'compatible'));
  assert.match(compatible.transformer.reference, /^[a-f0-9]{64}$/);
  assert.ok(Object.values(compatible.evidence.required_exports).every((value) => value === true));
  assert.notEqual(compatible.path, packagePath);
  // PHP resolves loaded files to canonical paths. A caller-owned alias to the
  // same snapshot directory must retain the same export and byte-identity proof.
  const canonicalDirectory = path.join(root, 'canonical');
  const aliasedDirectory = path.join(root, 'canonical-alias');
  fs.mkdirSync(canonicalDirectory);
  fs.symlinkSync(canonicalDirectory, aliasedDirectory, 'dir');
  const aliased = prepareTransformer({ ...context, transformer_path: packagePath }, aliasedDirectory);
  assert.equal(aliased.transformer.reference, compatible.transformer.reference);
  assert.ok(Object.values(aliased.evidence.required_exports).every((value) => value === true));
  const artifact = await runCapture(context, url, { capture: async () => capture(root) });
  const evaluate = (transformerPath, actionId, dependencies = {}) => runEvaluation({ context: { ...context, transformer_path: transformerPath }, url, capture: artifact, candidateSha: sha, actionId }, dependencies);
  const accepted = await evaluate(packagePath, 'i3', {
    matrixModule: {
      buildFixtureMatrixRunPlan: (input) => {
        assert.equal(input.mode, 'development-override');
        assert.equal(input.blocksEnginePhpTransformerReference, compatible.transformer.reference);
        assert.notEqual(input.blocksEnginePhpTransformerPath, packagePath);
        assert.ok(fs.existsSync(path.join(input.blocksEnginePhpTransformerPath, 'src', 'AssetAnalysis', 'SrcsetParser.php')));
        return { steps: [] };
      },
      summarizeBenchRun: () => ({ summary: { matrix_evidence_readiness: { fixtures: [] } } }),
    },
    spawn: (_command, args) => {
      assert.ok(args.includes('--blocks-engine-php-transformer-reference'));
      assert.ok(args.includes(compatible.transformer.reference));
      return { status: 0 };
    },
  });
  assert.equal(accepted.evaluation.transformer.reference, compatible.transformer.reference);
  const incompatible = path.join(root, 'incompatible');
  fs.cpSync(compatible.path, incompatible, { recursive: true });
  fs.rmSync(path.join(incompatible, 'src', 'AssetAnalysis', 'SrcsetParser.php'));
  let activations = 0;
  const result = await evaluate(incompatible, 'i1', { spawn: () => { ++activations; throw new Error('must not launch Codebox'); } });
  assert.equal(activations, 0);
  assert.equal(result.evaluation.status, 'blocked');
  assert.equal(evaluationVerdict(result.evaluation, result.report_path).blocker, 'transformer_dependency_incompatible');
  assert.match(result.evaluation.preparation_blocker.message, /required_exports_incompatible/);
  assert.equal(Object.values(result.evaluation.preparation_blocker.evidence.required_exports).filter(Boolean).length, 1);
  assert.notEqual(result.evaluation.preparation_blocker.evidence.transformer.reference, compatible.transformer.reference);
  await assert.rejects(evaluate(incompatible, 'i1'), /matrix_output_not_fresh/);
  // A filename alone is not an export, and invalid package identities cannot
  // silently select the consumer or independent CLI implementation.
  fs.writeFileSync(path.join(incompatible, 'src', 'AssetAnalysis', 'SrcsetParser.php'), '<?php // not an export');
  const forged = await evaluate(incompatible, 'i2');
  assert.match(forged.evaluation.preparation_blocker.message, /required_exports_incompatible/);
  for (const [action, source] of [['i5', path.join(root, 'missing')], ['i6', root]]) {
    const blocked = await evaluate(source, action);
    assert.equal(blocked.evaluation.status, 'blocked');
    assert.equal(blocked.evaluation.preparation_blocker.evidence.source, source);
  }
});

test('candidates bind a clean Git checkout to the exact supplied revision', () => {
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

test('repeated --fix-verify flags accumulate', () => {
  assert.deepEqual(parseArgs(['--url', url, '--fix-verify', 'a', '--fix-verify', 'b', '--max-iterations', '2']), { url, fixVerify: ['a', 'b'], maxIterations: '2' });
});

test('Homeboy accepts the generated definition', (context) => {
  const probe = spawnSync('homeboy', ['workflow', 'validate', '--help'], { encoding: 'utf8' });
  if (probe.status !== 0) {
    context.skip('installed Homeboy has no `workflow` command');
    return;
  }
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-workflow-validate-'));
  const file = path.join(root, 'workflow.json');
  fs.writeFileSync(file, JSON.stringify(build(root, { fixRepo: 'static-site-importer' }).workflow));
  const result = spawnSync('homeboy', ['workflow', 'validate', file], { encoding: 'utf8' });
  assert.equal(result.status, 0, `${result.stderr}\n${result.stdout}`);
  const data = JSON.parse(result.stdout).data;
  assert.equal(data.id, WORKFLOW_ID);
  assert.deepEqual(data.steps.map((item) => [item.id, item.kind]), [['capture', 'exec'], ['evaluate', 'exec'], ['fix', 'cook']]);
});
