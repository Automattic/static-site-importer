import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { verifySolvedSitePromotion } from './verify-solved-site-promotion.mjs';

const SSI_SHA = '1'.repeat(40);
const BE_SHA = '2'.repeat(40);
const WP_CODEBOX_SHA = '7'.repeat(40);

function fixture() {
  const root = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-promotion-'));
  for (const file of ['editor.png', 'source.png', 'candidate.png', 'diff.png', 'visual-diff.json']) fs.writeFileSync(path.join(root, file), file);
  const matrix = {
    schema: 'static-site-importer/fixture-matrix-result/v1',
    matrix_id: 'solved-matrix',
    summary: { generation_status: 'succeeded', execution_status: 'requested', fixture_count: 1, failed: 0, not_run: 0, solved_candidate_gate: { enabled: true, failed_fixture_count: 0 } },
    fixtures: [{
      fixture_id: 'solved', status: 'passed', success: true,
      quality_metrics: { pass: true, fallback_count: 0, core_html_block_count: 0, freeform_block_count: 0, invalid_block_count: 0 },
      block_composition: { block_total: 4, native_block_count: 4, core_html_block_count: 0 },
      editor_validation: { schema: 'wp-codebox/editor-validate-blocks/v1', validation_method: 'wp.blocks.validateBlock', validation_provider: 'wordpress-block-editor', content_source: 'edited-post-content', block_types_registered: 42, result_count: 4, results_complete: true, total_blocks: 4, valid_blocks: 4, invalid_blocks: 0 },
      editor_canvas: { status: 'captured', screenshot: path.join(root, 'editor.png') },
      editor_presentation: { schema: 'static-site-importer/editor-presentation-evidence/v2', provider_schema: 'wp-codebox/editor-presentation/v1', canvas_document_type: 'iframe', iframe_count: 1, expected_identity_count: 1, observed_identity_count: 1, expected_identities: ['a'.repeat(64)], observed_identities: ['a'.repeat(64)], missing_identities: [], expected_identities_complete: true, coverage_complete: true },
      visual_parity_artifacts: { metrics: { mismatch_ratio: 0, mismatch_pixels: 0 }, artifacts: Object.fromEntries([
        ['source_screenshot', 'source.png'], ['imported_screenshot', 'candidate.png'], ['diff_screenshot', 'diff.png'], ['visual_diff', 'visual-diff.json'],
      ].map(([slot, file]) => [slot, { status: 'captured', ref: { path: path.join(root, file) } }])) },
      matrix_evidence: { readiness: 'verified', missing: [], transformer: { package_reference: BE_SHA }, materialization_receipt: { status: 'completed', plan_hash: 'abc' } },
      editor_quality: { native_conversion_rate: 1 },
    }],
  };
  matrix.fixtures[0].fixture_corpus = 'solved';
  const registry = {
    schema: 'static-site-importer/gutenberg-incompatibility-registry/v1',
    matrix_id: matrix.matrix_id,
    generated_from: { result_schema: matrix.schema, fixture_count: matrix.fixtures.length },
    fixture_decisions: [{ fixture_id: 'solved', fixture_corpus: 'solved', acceptance_status: 'solved_candidate' }],
  };
  const runtime = { nodeVersion: '20.19.4', phpVersion: '8.1.29', wordpressVersion: '7.0.4', homeboyVersion: 'v0.298.1', homeboySha256: '3'.repeat(64), homeboyExtensionsRef: '4'.repeat(40), wpCodeboxVersion: 'v0.21.0', wpCodeboxSha256: '5'.repeat(64), wpCodeboxSha: WP_CODEBOX_SHA, staticSiteImporterSha: SSI_SHA, blocksEngineSha: BE_SHA };
  const paths = { matrix: path.join(root, 'matrix.json'), registry: path.join(root, 'registry.json'), runtime: path.join(root, 'runtime.json') };
  write(paths.matrix, matrix); write(paths.registry, registry); write(paths.runtime, runtime);
  return { root, matrix, registry, runtime, paths, options: { matrixResult: paths.matrix, registry: paths.registry, runtimeInputs: paths.runtime, artifactRoot: root, staticSiteImporterSha: SSI_SHA, blocksEngineSha: BE_SHA, wpCodeboxSha: WP_CODEBOX_SHA, fixtureTreeSha: '6'.repeat(40), solvedFixtureCount: 1, solvedFixtureIds: 'solved', runUrl: 'https://github.com/Automattic/static-site-importer/actions/runs/123', artifactUrl: 'https://github.com/Automattic/static-site-importer/actions/runs/123#artifacts', output: path.join(root, 'receipt.json'), manifestOutput: path.join(root, 'manifest.json') } };
}

function acceptedProviderSubmission() {
  return {
    schema: 'static-site-importer/provider-submission-evidence/v1',
    status: 'accepted',
    forms: [{
      schema: 'static-site-importer/provider-submission-evidence/v1',
      status: 'accepted',
      binding: {
        page_path: 'index.html',
        form_identity: 'index.html\nform.signup',
        provider_id: 'jetpack',
        provider_version: '16.0.1',
        plan_hash: 'abc',
        materialization_receipt: { status: 'completed', plan_hash: 'abc' },
      },
      request: { url: 'http://nimbus.test/', owner: 'wordpress', source_endpoint_retained: false },
      receipt: { type: 'feedback', id: 'feedback-1', local: true },
      behaviors: { required_field_failure: 'passed', valid_success: 'passed', provider_failure: 'passed', duplicate_submit: 'passed' },
      notification: { capable: false, transport: 'none', reason: 'no_external_mail_transport', sent: false },
    }],
  };
}

test('issues an accepted immutable promotion receipt', () => {
  const input = fixture();
  const receipt = verifySolvedSitePromotion(input.options);
  assert.equal(receipt.status, 'accepted');
  assert.equal(receipt.candidate.blocks_engine_sha, BE_SHA);
  assert.equal(receipt.corpus.selected_fixture_count, 1);
  assert.ok(receipt.evidence.artifacts.every((row) => /^[a-f0-9]{64}$/.test(row.sha256)));
});

test('accepts complete v1 presentation evidence with complete raw plan provenance', () => {
  const input = fixture();
  const presentation = input.matrix.fixtures[0].editor_presentation;
  presentation.schema = 'static-site-importer/editor-presentation-evidence/v1';
  delete presentation.expected_identities_complete;
  input.matrix.fixtures[0].import_report = { blocks_engine: { wordpress_site_plan: { asset_count: 1, assets: [{ kind: 'css', content_hash: 'a'.repeat(64), scopes: [{ kind: 'global' }] }] } } };
  write(input.paths.matrix, input.matrix);

  assert.equal(verifySolvedSitePromotion(input.options).status, 'accepted');
});

test('accepts complete parent-document editor presentation evidence', () => {
  const input = fixture();
  input.matrix.fixtures[0].editor_presentation.canvas_document_type = 'parent';
  input.matrix.fixtures[0].editor_presentation.iframe_count = 0;
  write(input.paths.matrix, input.matrix);

  assert.equal(verifySolvedSitePromotion(input.options).status, 'accepted');
});

test('accepts mapped provider forms with WordPress-owned local receipt evidence', () => {
  const input = fixture();
  input.matrix.fixtures[0].import_report = { provider_submission: acceptedProviderSubmission() };
  write(input.paths.matrix, input.matrix);
  assert.equal(verifySolvedSitePromotion(input.options).status, 'accepted');
});

test('accepts a completed v2 materialization receipt identity', () => {
  const input = fixture();
  input.matrix.fixtures[0].matrix_evidence.materialization_receipt = {
    status: 'completed',
    plan_identity: { schema: 'blocks-engine/wordpress-site-plan-identity/v1', hash: 'a'.repeat(64) },
  };
  write(input.paths.matrix, input.matrix);

  assert.equal(verifySolvedSitePromotion(input.options).status, 'accepted');
});

test('pins an immutable WP Codebox release package, commit, and checksum together', () => {
  const workflow = fs.readFileSync(path.resolve('.github/workflows/solved-site-promotion.yml'), 'utf8');
  const caller = fs.readFileSync(path.resolve('.github/workflows/solved-site-promotion-pr.yml'), 'utf8');
  assert.match(workflow, /WP_CODEBOX_VERSION: v0\.21\.0/);
  assert.match(workflow, /WP_CODEBOX_WORKSPACE_ASSET: wp-codebox-workspace-0\.21\.0\.tgz/);
  assert.match(workflow, /WP_CODEBOX_SHA256: 8f5fdd58ff4c78186155e29de2dec07004ac2eacba93131bb39d32f466272990/);
  assert.match(workflow, /WP_CODEBOX_SHA: b1ef4aa66a34924c3760d5176c58a497d7eaabcd/);
  assert.match(workflow, /releases\/download\/\$\{WP_CODEBOX_VERSION\}\/\$\{WP_CODEBOX_WORKSPACE_ASSET\}/);
  assert.match(workflow, /sha256sum --check --status/);
  assert.doesNotMatch(workflow, /Checkout WP Codebox candidate|npm pack --pack-destination|wp-codebox-sha:/);
  assert.match(workflow, /wpCodeboxSha:process\.env\.WP_CODEBOX_SHA/);
  assert.match(caller, /blocks-engine-sha: ae9716efb388ffa338ed0aa6d1b423f6eca3082c/);
  assert.doesNotMatch(caller, /wp-codebox-sha:/);
});

test('resolves uniquely named durable copies of transient runtime evidence', () => {
  const input = fixture();
  const durableEditor = path.join(input.root, 'uuid-editor.png');
  fs.renameSync(path.join(input.root, 'editor.png'), durableEditor);
  input.matrix.fixtures[0].editor_canvas.screenshot = '/transient/homeboy/editor.png';
  write(input.paths.matrix, input.matrix);
  const receipt = verifySolvedSitePromotion(input.options);
  assert.ok(receipt.evidence.artifacts.some((row) => row.path === 'uuid-editor.png'));
});

test('materializes host runtime evidence into the durable artifact root', () => {
  const input = fixture();
  const externalRoot = fs.mkdtempSync(path.join(os.tmpdir(), 'ssi-promotion-runtime-'));
  const externalEditor = path.join(externalRoot, 'editor.png');
  fs.writeFileSync(externalEditor, 'runtime editor screenshot');
  input.matrix.fixtures[0].editor_canvas.screenshot = externalEditor;
  write(input.paths.matrix, input.matrix);
  const receipt = verifySolvedSitePromotion(input.options);
  const artifact = receipt.evidence.artifacts.find((row) => row.path.endsWith('-editor.png'));
  assert.match(artifact?.path || '', /^runtime-evidence\/[a-f0-9]{64}-editor\.png$/);
  assert.equal(fs.readFileSync(path.join(input.root, artifact.path), 'utf8'), 'runtime editor screenshot');
});

for (const [name, mutate, pattern] of [
  ['empty corpus', (input) => { input.matrix.fixtures = []; input.matrix.summary.fixture_count = 0; }, /non-empty/],
  ['failed decision', (input) => { input.registry.fixture_decisions[0].acceptance_status = 'visual_only_blocker'; }, /solved_candidate/],
  ['partial receipt', (input) => { input.matrix.fixtures[0].matrix_evidence.materialization_receipt.status = 'partial'; }, /materialization receipt/],
  ['zero editor blocks', (input) => { input.matrix.fixtures[0].editor_validation.total_blocks = 0; input.matrix.fixtures[0].editor_validation.valid_blocks = 0; }, /editor validation/],
  ['counts-only editor evidence', (input) => { delete input.matrix.fixtures[0].editor_validation.schema; }, /browser artifact schema/],
  ['detached editor content', (input) => { input.matrix.fixtures[0].editor_validation.content_source = 'argument'; }, /loaded post editor content/],
  ['missing registered block types', (input) => { input.matrix.fixtures[0].editor_validation.block_types_registered = 0; }, /registered block types/],
  ['incomplete recursive results', (input) => { input.matrix.fixtures[0].editor_validation.result_count = 3; }, /recursive result/],
  ['mapped forms without submission evidence', (input) => { input.matrix.fixtures[0].import_report = { entity_lifecycle: { entities: { form: { counts: { mapped: 1 }, forms: [{ runtime_mapped: true }] } } } }; }, /provider submission evidence is missing/],
  ['failed provider submission', (input) => { input.matrix.fixtures[0].import_report = { provider_submission: { schema: 'static-site-importer/provider-submission-evidence/v1', status: 'failed', code: 'provider_cannot_accept_submissions' } }; }, /not accepted/],
  ['source endpoint retained', (input) => { const evidence = acceptedProviderSubmission(); evidence.forms[0].request.source_endpoint_retained = true; input.matrix.fixtures[0].import_report = { provider_submission: evidence }; }, /WordPress-owned endpoint/],
  ['missing local receipt', (input) => { const evidence = acceptedProviderSubmission(); delete evidence.forms[0].receipt.id; input.matrix.fixtures[0].import_report = { provider_submission: evidence }; }, /local receipt/],
  ['missing notification capability', (input) => { const evidence = acceptedProviderSubmission(); delete evidence.forms[0].notification; input.matrix.fixtures[0].import_report = { provider_submission: evidence }; }, /notification capability/],
  ['missing editor presentation', (input) => { delete input.matrix.fixtures[0].editor_presentation; }, /editor presentation evidence/],
  ['incomplete editor stylesheet coverage', (input) => { input.matrix.fixtures[0].editor_presentation.coverage_complete = false; input.matrix.fixtures[0].editor_presentation.missing_identities = ['a'.repeat(64)]; }, /stylesheet coverage/],
  ['contradictory editor presentation identities', (input) => { input.matrix.fixtures[0].editor_presentation.observed_identities = []; input.matrix.fixtures[0].editor_presentation.observed_identity_count = 0; }, /stylesheet coverage/],
  ['contradictory parent canvas iframe count', (input) => { input.matrix.fixtures[0].editor_presentation.canvas_document_type = 'parent'; }, /stylesheet coverage/],
  ['visual mismatch', (input) => { input.matrix.fixtures[0].visual_parity_artifacts.metrics.mismatch_pixels = 1; }, /visual mismatch/],
  ['fallback block', (input) => { input.matrix.fixtures[0].quality_metrics.core_html_block_count = 1; }, /core_html_block_count/],
  ['non-native conversion', (input) => { input.matrix.fixtures[0].editor_quality.native_conversion_rate = 0.99; }, /native conversion rate/],
  ['transformer mismatch', (input) => { input.matrix.fixtures[0].matrix_evidence.transformer.package_reference = '7'.repeat(40); }, /provenance/],
  ['unpinned runtime', (input) => { input.runtime.wordpressVersion = 'latest'; }, /pinned/],
  ['missing evidence file', (input) => { fs.unlinkSync(path.join(input.root, 'diff.png')); }, /missing/],
  ['missing quality metric', (input) => { delete input.matrix.fixtures[0].quality_metrics.core_html_block_count; }, /present/],
  ['null quality metric', (input) => { input.matrix.fixtures[0].quality_metrics.core_html_block_count = null; }, /finite number/],
  ['boolean quality metric', (input) => { input.matrix.fixtures[0].quality_metrics.core_html_block_count = false; }, /finite number/],
  ['empty quality metric', (input) => { input.matrix.fixtures[0].quality_metrics.core_html_block_count = ''; }, /finite number/],
  ['wrong matrix corpus', (input) => { input.matrix.fixtures[0].fixture_corpus = 'active'; }, /solved corpus/],
  ['null block total', (input) => { input.matrix.fixtures[0].block_composition.block_total = null; }, /finite number/],
  ['boolean native block count', (input) => { input.matrix.fixtures[0].block_composition.native_block_count = true; }, /finite number/],
  ['array total blocks', (input) => { input.matrix.fixtures[0].editor_validation.total_blocks = []; }, /finite number/],
  ['whitespace total blocks', (input) => { input.matrix.fixtures[0].editor_validation.total_blocks = '  '; }, /finite number/],
  ['null valid blocks', (input) => { input.matrix.fixtures[0].editor_validation.valid_blocks = null; }, /finite number/],
  ['boolean native conversion rate', (input) => { input.matrix.fixtures[0].editor_quality.native_conversion_rate = true; }, /finite number/],
  ['partial canonical corpus', (input) => { input.options.solvedFixtureIds = 'solved,other'; input.options.solvedFixtureCount = 2; }, /complete canonical solved corpus/],
  ['duplicate canonical ids', (input) => { input.options.solvedFixtureIds = 'solved,solved'; }, /unique/],
  ['registry matrix mismatch', (input) => { input.registry.matrix_id = 'other-matrix'; }, /matrix_id/],
  ['registry source schema mismatch', (input) => { input.registry.generated_from.result_schema = 'other/schema'; }, /result_schema/],
  ['registry fixture count mismatch', (input) => { input.registry.generated_from.fixture_count = 2; }, /fixture_count/],
  ['registry solved decision missing', (input) => { input.registry.fixture_decisions[0].fixture_corpus = 'active'; }, /solved fixture decision/],
]) {
  test(`fails closed for ${name}`, () => {
    const input = fixture(); mutate(input); write(input.paths.matrix, input.matrix); write(input.paths.registry, input.registry); write(input.paths.runtime, input.runtime);
    assert.throws(() => verifySolvedSitePromotion(input.options), pattern);
  });
}

function write(file, value) { fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`); }
