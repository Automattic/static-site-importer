import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';
import { verifySolvedSitePromotion } from './verify-solved-site-promotion.mjs';
import { sha256 } from '../lib/fixture-matrix/provider-submission-evidence.mjs';

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
      editor_presentation: { schema: 'static-site-importer/editor-presentation-evidence/v3', provider_schema: 'wp-codebox/editor-presentation/v1', canvas_document_type: 'iframe', iframe_count: 1, expected_identity_count: 1, observed_identity_count: 1, expected_identities: ['a'.repeat(64)], observed_identities: ['a'.repeat(64)], missing_identities: [], expected_identities_complete: true, coverage_complete: true, idle_canvas: { schema: 'wp-codebox/editor-idle-canvas/v1', status: 'captured', onboarding_modal_count: 0 }, matched_rendering: { schema: 'wp-codebox/editor-presentation-match/v1', status: 'passed', equivalent_canvas_widths: true, major_geometry_drift: false, unreadable_content: false, hidden_content: false, unresolved_asset_count: 0, frontend_screenshot: path.join(root, 'source.png'), editor_screenshot: path.join(root, 'editor.png'), diff_screenshot: path.join(root, 'diff.png') } },
      editor_interaction: { schema: 'static-site-importer/editor-interaction-evidence/v1', provider_schema: 'wp-codebox/editor-actions/v1', selection: { status: 'ok' }, text_mutation: { status: 'ok', mutation_status: 'applied' }, block_movement: { status: 'ok', mutation_status: 'applied' }, save: { schema: 'wp-codebox/editor-save/v1', status: 'saved', marker_present: true }, reload: { status: 'ok' }, post_save_validation: { schema: 'wp-codebox/editor-validity/v1', status: 'clean' } },
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
  const runtime = { nodeVersion: '20.19.4', phpVersion: '8.1.29', wordpressVersion: '7.0.4', homeboyVersion: 'v0.298.1', homeboySha256: '3'.repeat(64), homeboyExtensionsRef: '4'.repeat(40), wpCodeboxVersion: 'v0.26.0', wpCodeboxSha256: '5'.repeat(64), wpCodeboxSha: WP_CODEBOX_SHA, staticSiteImporterSha: SSI_SHA, blocksEngineSha: BE_SHA };
  const paths = { matrix: path.join(root, 'matrix.json'), registry: path.join(root, 'registry.json'), runtime: path.join(root, 'runtime.json') };
  write(paths.matrix, matrix); write(paths.registry, registry); write(paths.runtime, runtime);
  return { root, matrix, registry, runtime, paths, options: { matrixResult: paths.matrix, registry: paths.registry, runtimeInputs: paths.runtime, artifactRoot: root, staticSiteImporterSha: SSI_SHA, blocksEngineSha: BE_SHA, wpCodeboxSha: WP_CODEBOX_SHA, fixtureTreeSha: '6'.repeat(40), solvedFixtureCount: 1, solvedFixtureIds: 'solved', runUrl: 'https://github.com/Automattic/static-site-importer/actions/runs/123', artifactUrl: 'https://github.com/Automattic/static-site-importer/actions/runs/123#artifacts', output: path.join(root, 'receipt.json'), manifestOutput: path.join(root, 'manifest.json') } };
}

test('issues an accepted immutable promotion receipt', () => {
  const input = fixture();
  const receipt = verifySolvedSitePromotion(input.options);
  assert.equal(receipt.status, 'accepted');
  assert.equal(receipt.candidate.blocks_engine_sha, BE_SHA);
  assert.equal(receipt.corpus.selected_fixture_count, 1);
  assert.ok(receipt.evidence.artifacts.every((row) => /^[a-f0-9]{64}$/.test(row.sha256)));
});

test('gates declared provider submission evidence and includes its runtime artifact', () => {
  const input = fixture();
  const row = input.matrix.fixtures[0];
  const planHash = 'a'.repeat(64);
  row.matrix_evidence.materialization_receipt.plan_hash = planHash;
  row.provider_submissions = [{ required: true, page_route: '/contact', form_identity: 'b'.repeat(64), provider_id: 'wordpress/forms', provider_owner: 'wordpress' }];
  row.surface_lineage = [{ surface: { source_entry: '/contact' }, materialized_document: { post_id: 7 } }];
  row.provider_submission_evidence = [{
    schema: 'static-site-importer/provider-submission-evidence/v1', fixture_id: 'solved', page: { route: '/contact', wordpress_entity_id: '7' }, form_identity: 'b'.repeat(64),
    provider: { id: 'wordpress/forms', version: '1.2.3', ownership: 'wordpress', submission_endpoint: { scope: 'wordpress-local', source_endpoint_contacted: false } }, network: { external_request_origins: [] },
    plan_hash: planHash, materialization_receipt_sha256: sha256(row.matrix_evidence.materialization_receipt),
    behaviors: { required_field_failure: { status: 'passed', ui: 'validation_error', local_receipt_count: 0 }, valid_submission: { status: 'passed', ui: 'success', local_receipt: { id: 'local-1', sha256: 'c'.repeat(64), storage: 'wordpress-local' } }, provider_failure: { status: 'passed', ui: 'provider_error', local_receipt_count: 0 }, duplicate_submit: { status: 'passed', ui: 'success', local_receipt_count: 1, receipt_sha256: 'c'.repeat(64) } },
    notification: { capability: 'separate', attempted: false }, artifact_ref: { path: 'provider-submission.json' },
  }];
  fs.writeFileSync(path.join(input.root, 'provider-submission.json'), JSON.stringify(row.provider_submission_evidence[0]));
  write(input.paths.matrix, input.matrix);
  const receipt = verifySolvedSitePromotion(input.options);
  assert.equal(receipt.gates.provider_submissions, 'passed');
  assert.ok(receipt.evidence.artifacts.some((artifact) => artifact.path === 'provider-submission.json'));
});

test('fails closed when a required provider submission has no evidence', () => {
  const input = fixture();
  input.matrix.fixtures[0].provider_submissions = [{ required: true, page_route: '/contact', form_identity: 'b'.repeat(64), provider_id: 'wordpress/forms', provider_owner: 'wordpress' }];
  write(input.paths.matrix, input.matrix);
  assert.throws(() => verifySolvedSitePromotion(input.options), /provider submission evidence failed/);
});

test('rejects legacy stylesheet-only presentation evidence despite complete raw plan provenance', () => {
  const input = fixture();
  const presentation = input.matrix.fixtures[0].editor_presentation;
  presentation.schema = 'static-site-importer/editor-presentation-evidence/v1';
  delete presentation.expected_identities_complete;
  input.matrix.fixtures[0].import_report = { blocks_engine: { wordpress_site_plan: { asset_count: 1, assets: [{ kind: 'css', content_hash: 'a'.repeat(64), scopes: [{ kind: 'global' }] }] } } };
  write(input.paths.matrix, input.matrix);

  assert.throws(() => verifySolvedSitePromotion(input.options), /matched editor presentation evidence/);
});

test('accepts complete parent-document editor presentation evidence', () => {
  const input = fixture();
  input.matrix.fixtures[0].editor_presentation.canvas_document_type = 'parent';
  input.matrix.fixtures[0].editor_presentation.iframe_count = 0;
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
  assert.match(workflow, /WP_CODEBOX_VERSION: v0\.26\.8/);
  assert.match(workflow, /WP_CODEBOX_WORKSPACE_ASSET: wp-codebox-workspace-0\.26\.8\.tgz/);
  assert.match(workflow, /WP_CODEBOX_SHA256: e60ae51766d57587b384da3eeed45393a5be6e9b5f1cf4a9b515423b643912a2/);
  assert.match(workflow, /WP_CODEBOX_SHA: 6a152aa89fe518d21e13b194ac488ae22fdd9eb8/);
  assert.match(workflow, /releases\/download\/\$\{WP_CODEBOX_VERSION\}\/\$\{WP_CODEBOX_WORKSPACE_ASSET\}/);
  assert.match(workflow, /sha256sum --check --status/);
  assert.doesNotMatch(workflow, /Checkout WP Codebox candidate|npm pack --pack-destination|wp-codebox-sha:/);
  assert.match(workflow, /wpCodeboxSha:process\.env\.WP_CODEBOX_SHA/);
  assert.match(workflow, /"\$WP_CODEBOX_BIN" recipe validate --recipe "\$CONTRACT_RECIPE" --json/);
  assert.ok(workflow.indexOf('recipe validate --recipe') < workflow.indexOf('playwright/cli.js" install --with-deps chromium'));
  assert.match(caller, /blocks-engine-sha: 3a8cebeb2d07cf26d47a2b14c20242e2f4eb4278/);
  assert.doesNotMatch(caller, /wp-codebox-sha:/);
});

test('resolves uniquely named durable copies of transient runtime evidence', () => {
  const input = fixture();
  const durableEditor = path.join(input.root, 'uuid-editor.png');
  fs.renameSync(path.join(input.root, 'editor.png'), durableEditor);
  input.matrix.fixtures[0].editor_canvas.screenshot = '/transient/homeboy/editor.png';
  input.matrix.fixtures[0].editor_presentation.matched_rendering.editor_screenshot = '/transient/homeboy/editor.png';
  write(input.paths.matrix, input.matrix);
  const receipt = verifySolvedSitePromotion(input.options);
  assert.ok(receipt.evidence.artifacts.some((row) => row.path === 'uuid-editor.png'));
});

test('resolves fixture-specific Homeboy artifacts when canonical paths are absent', () => {
  const input = fixture();
  const expected = 'files/browser/editor-open/solved/presentation-frontend.png';
  const selected = path.join(input.root, 'uuid-selected-presentation-frontend.png');
  const other = path.join(input.root, 'uuid-other-presentation-frontend.png');
  fs.writeFileSync(selected, 'selected fixture');
  fs.writeFileSync(other, 'other fixture');
  input.matrix.fixtures[0].editor_presentation.matched_rendering.frontend_screenshot = expected;
  write(input.paths.matrix, input.matrix);
  write(path.join(input.root, 'homeboy-bench-result.json'), {
    data: { payload: { artifacts: [
      { name: 'editor_canvas_solved_editor-open-screenshots-1', path: selected },
      { name: 'editor_canvas_other_editor-open-screenshots-1', path: other },
    ] } },
  });

  const receipt = verifySolvedSitePromotion(input.options);
  assert.ok(receipt.evidence.artifacts.some((row) => row.path === path.basename(selected)));
  assert.ok(!receipt.evidence.artifacts.some((row) => row.path === path.basename(other)));
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
  ['missing editor presentation', (input) => { delete input.matrix.fixtures[0].editor_presentation; }, /editor presentation evidence/],
  ['incomplete editor stylesheet coverage', (input) => { input.matrix.fixtures[0].editor_presentation.coverage_complete = false; input.matrix.fixtures[0].editor_presentation.missing_identities = ['a'.repeat(64)]; }, /editor presentation evidence/],
  ['contradictory editor presentation identities', (input) => { input.matrix.fixtures[0].editor_presentation.observed_identities = []; input.matrix.fixtures[0].editor_presentation.observed_identity_count = 0; }, /editor presentation evidence/],
  ['contradictory parent canvas iframe count', (input) => { input.matrix.fixtures[0].editor_presentation.canvas_document_type = 'parent'; }, /editor presentation evidence/],
  ['onboarding modal in idle canvas', (input) => { input.matrix.fixtures[0].editor_presentation.idle_canvas.onboarding_modal_count = 1; }, /editor presentation evidence/],
  ['major editor geometry drift', (input) => { input.matrix.fixtures[0].editor_presentation.matched_rendering.major_geometry_drift = true; }, /editor presentation evidence/],
  ['unresolved editor asset', (input) => { input.matrix.fixtures[0].editor_presentation.matched_rendering.unresolved_asset_count = 1; }, /editor presentation evidence/],
  ['missing matched editor artifact', (input) => { input.matrix.fixtures[0].editor_presentation.matched_rendering.diff_screenshot = ''; }, /editor presentation evidence/],
  ['missing editor interaction', (input) => { delete input.matrix.fixtures[0].editor_interaction; }, /interaction evidence/],
  ['editor movement no-op', (input) => { input.matrix.fixtures[0].editor_interaction.block_movement.mutation_status = 'no-op'; }, /interaction evidence/],
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
