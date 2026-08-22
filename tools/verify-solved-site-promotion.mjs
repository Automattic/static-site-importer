#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { editorPresentationEvidenceComplete } from '../lib/fixture-matrix/gutenberg-incompatibility-registry.mjs';

export const RECEIPT_SCHEMA = 'static-site-importer/solved-site-promotion-receipt/v1';
const MATRIX_SCHEMA = 'static-site-importer/fixture-matrix-result/v1';
const REGISTRY_SCHEMA = 'static-site-importer/gutenberg-incompatibility-registry/v1';

export function verifySolvedSitePromotion(input) {
  const options = normalizeOptions(input);
  const matrix = readJson(options.matrixResult, 'matrix result');
  const registry = readJson(options.registry, 'registry');
  const runtime = readJson(options.runtimeInputs, 'runtime inputs');
  assert(matrix.schema === MATRIX_SCHEMA, `Matrix schema must be ${MATRIX_SCHEMA}.`);
  assert(registry.schema === REGISTRY_SCHEMA, `Registry schema must be ${REGISTRY_SCHEMA}.`);
  assertSha(options.staticSiteImporterSha, 'Static Site Importer candidate SHA');
  assertSha(options.blocksEngineSha, 'Blocks Engine candidate SHA');
  assertSha(options.wpCodeboxSha, 'WP Codebox candidate SHA');
  assertSha(options.fixtureTreeSha, 'Fixture tree SHA');
  assert(Number(options.solvedFixtureCount) === options.solvedFixtureIds.length,
    `--solved-fixture-count must equal the number of --solved-fixture-ids (${options.solvedFixtureIds.length}).`);
  assert(matrix.summary?.execution_status === 'requested', 'Matrix execution was not requested.');
  assert(matrix.summary?.generation_status === 'succeeded', 'Matrix generation did not succeed.');
  assert(Array.isArray(matrix.fixtures) && matrix.fixtures.length > 0, 'Selected fixture corpus must be non-empty.');
  assert(matrix.summary?.fixture_count === matrix.fixtures.length, 'Matrix fixture count is inconsistent.');
  assert(matrix.summary?.failed === 0 && matrix.summary?.not_run === 0, 'Every selected fixture must pass execution.');
  assert(matrix.summary?.solved_candidate_gate?.enabled === true, 'Solved-candidate gate must be enabled.');
  assert(matrix.summary?.solved_candidate_gate?.failed_fixture_count === 0, 'Solved-candidate gate reported failures.');

  for (const fixture of matrix.fixtures) {
    assert(fixture.fixture_corpus === 'solved', `${fixture.fixture_id}: matrix fixture must belong to the solved corpus.`);
  }
  const matrixFixtureIds = matrix.fixtures.map((fixture) => String(fixture.fixture_id)).sort();
  assert(JSON.stringify(matrixFixtureIds) === JSON.stringify(options.solvedFixtureIds),
    `Matrix must cover the complete canonical solved corpus: expected [${options.solvedFixtureIds.join(',')}] got [${matrixFixtureIds.join(',')}]`);

  assert(matrix.matrix_id, 'Matrix result is missing matrix_id.');
  assert(registry.matrix_id === matrix.matrix_id, 'Registry matrix_id must match the matrix result matrix_id.');
  assert(registry.generated_from?.result_schema === matrix.schema, 'Registry generated_from.result_schema must match the matrix schema.');
  assert(Number(registry.generated_from?.fixture_count) === matrix.fixtures.length, 'Registry generated_from.fixture_count must match the matrix fixture count.');

  validateRuntime(runtime, options);
  const decisions = new Map((registry.fixture_decisions || []).map((row) => [row.fixture_id, row]));
  const registrySolvedIds = (registry.fixture_decisions || [])
    .filter((row) => row.fixture_corpus === 'solved')
    .map((row) => String(row.fixture_id))
    .sort();
  assert(JSON.stringify(registrySolvedIds) === JSON.stringify(options.solvedFixtureIds),
    `Registry must carry a solved fixture decision for every canonical solved fixture id: expected [${options.solvedFixtureIds.join(',')}] got [${registrySolvedIds.join(',')}]`);
  const requiredFiles = [options.matrixResult, options.registry, options.runtimeInputs];
  for (const fixture of matrix.fixtures) {
    verifyFixture(fixture, decisions.get(fixture.fixture_id), options, requiredFiles);
  }

  const artifacts = artifactManifest(requiredFiles, options.artifactRoot);
  const receipt = {
    schema: RECEIPT_SCHEMA,
    status: 'accepted',
    candidate: {
      static_site_importer_sha: options.staticSiteImporterSha,
      blocks_engine_sha: options.blocksEngineSha,
    },
    runtime: {
      node_version: runtime.nodeVersion,
      php_version: runtime.phpVersion,
      wordpress_version: runtime.wordpressVersion,
      homeboy_version: runtime.homeboyVersion,
      homeboy_sha256: runtime.homeboySha256,
      homeboy_extensions_ref: runtime.homeboyExtensionsRef,
      wp_codebox_version: runtime.wpCodeboxVersion,
      wp_codebox_sha256: runtime.wpCodeboxSha256,
    },
    corpus: {
      fixture_root_tree_sha: options.fixtureTreeSha,
      solved_fixture_count: options.solvedFixtureIds.length,
      selected_fixture_ids: options.solvedFixtureIds,
      selected_fixture_count: options.solvedFixtureIds.length,
    },
    gates: {
      matrix: 'passed',
      solved_candidate: 'passed',
      materialization_receipts: 'passed',
      editor: 'passed',
      visual: 'passed',
      native_blocks: 'passed',
      artifacts: 'passed',
    },
    evidence: {
      run_url: options.runUrl,
      artifact_manifest_url: options.artifactUrl,
      artifacts,
    },
  };
  fs.writeFileSync(options.manifestOutput, `${JSON.stringify({ schema: 'static-site-importer/solved-site-artifact-manifest/v1', artifacts }, null, 2)}\n`);
  fs.writeFileSync(options.output, `${JSON.stringify(receipt, null, 2)}\n`);
  return receipt;
}

function verifyFixture(fixture, decision, options, requiredFiles) {
  const id = String(fixture.fixture_id || 'unknown');
  assert(fixture.status === 'passed' && fixture.success === true, `${id}: fixture did not pass.`);
  assert(decision?.acceptance_status === 'solved_candidate', `${id}: acceptance status is not solved_candidate.`);
  const quality = fixture.quality_metrics || {};
  assert(quality.pass === true, `${id}: quality gate did not pass.`);
  for (const key of ['fallback_count', 'core_html_block_count', 'freeform_block_count', 'invalid_block_count']) {
    assertFiniteMetric(quality, key, id);
    assert(Number(quality[key]) === 0, `${id}: ${key} must be zero.`);
  }
  const composition = fixture.block_composition || {};
  assertFiniteMetric(composition, 'block_total', id);
  assert(Number(composition.block_total) > 0, `${id}: imported block count must be nonzero.`);
  assertFiniteMetric(composition, 'native_block_count', id);
  assert(Number(composition.native_block_count) === Number(composition.block_total), `${id}: every imported block must be native.`);
  assertFiniteMetric(composition, 'core_html_block_count', id);
  assert(Number(composition.core_html_block_count) === 0, `${id}: core/html blocks are forbidden.`);
  const editor = fixture.editor_validation || {};
  assert(editor.schema === 'wp-codebox/editor-validate-blocks/v1', `${id}: editor validation must carry the WP Codebox browser artifact schema.`);
  assert(editor.validation_method === 'wp.blocks.validateBlock', `${id}: editor validation must use wp.blocks.validateBlock.`);
  assert(editor.validation_provider === 'wordpress-block-editor', `${id}: editor validation must use the WordPress block editor provider.`);
  assert(editor.content_source === 'edited-post-content', `${id}: editor validation must inspect the loaded post editor content.`);
  assert(Number(editor.block_types_registered) > 0, `${id}: editor validation must load registered block types.`);
  assertFiniteMetric(editor, 'total_blocks', id);
  assertFiniteMetric(editor, 'valid_blocks', id);
  assertFiniteMetric(editor, 'invalid_blocks', id);
  assertFiniteMetric(editor, 'result_count', id);
  assert(editor.results_complete === true && Number(editor.result_count) === Number(editor.total_blocks), `${id}: editor validation must include one complete recursive result per block.`);
  assert(Number(editor.total_blocks) > 0 && editor.valid_blocks === editor.total_blocks && Number(editor.invalid_blocks) === 0, `${id}: editor validation is incomplete or invalid.`);
  assert(fixture.editor_canvas?.status === 'captured', `${id}: editor canvas evidence is missing.`);
  addRequiredFile(requiredFiles, fixture.editor_canvas?.screenshot, `${id}: editor screenshot`, options.artifactRoot);
  const editorPresentation = fixture.editor_presentation || {};
  assert(['static-site-importer/editor-presentation-evidence/v1', 'static-site-importer/editor-presentation-evidence/v2'].includes(editorPresentation.schema), `${id}: editor presentation evidence is missing.`);
  assert(editorPresentation.provider_schema === 'wp-codebox/editor-presentation/v1', `${id}: editor presentation must use WP Codebox canvas evidence.`);
  assert(Number(editorPresentation.expected_identity_count) > 0, `${id}: editor presentation has no expected generated styles.`);
  assert(editorPresentationEvidenceComplete(editorPresentation, fixture), `${id}: editor presentation stylesheet coverage is incomplete or contradictory.`);
  const visual = fixture.visual_parity_artifacts || {};
  const visualMetrics = visual.metrics || {};
  assertFiniteMetric(visualMetrics, 'mismatch_ratio', id);
  assertFiniteMetric(visualMetrics, 'mismatch_pixels', id);
  assert(Number(visualMetrics.mismatch_ratio) === 0 && Number(visualMetrics.mismatch_pixels) === 0, `${id}: visual mismatch must be exactly zero.`);
  for (const slot of ['source_screenshot', 'imported_screenshot', 'diff_screenshot', 'visual_diff']) {
    const artifact = visual.artifacts?.[slot];
    assert(artifact?.status === 'captured', `${id}: ${slot} evidence is missing.`);
    addRequiredFile(requiredFiles, artifact?.ref?.path, `${id}: ${slot}`, options.artifactRoot);
  }
  const evidence = fixture.matrix_evidence || {};
  assert(evidence.readiness === 'verified' && (evidence.missing || []).length === 0, `${id}: runtime evidence is incomplete.`);
  const receipt = evidence.materialization_receipt || {};
  const receiptIdentity = receipt.plan_identity || {};
  assert(receipt.status === 'completed' && (receipt.plan_hash || (receiptIdentity.schema === 'blocks-engine/wordpress-site-plan-identity/v1' && receiptIdentity.hash)), `${id}: completed materialization receipt is missing.`);
  assert(evidence.transformer?.package_reference === options.blocksEngineSha, `${id}: transformer provenance does not match the Blocks Engine candidate.`);
  const editorQuality = fixture.editor_quality || {};
  assertFiniteMetric(editorQuality, 'native_conversion_rate', id);
  assert(Number(editorQuality.native_conversion_rate) === 1, `${id}: native conversion rate must equal 1.`);
}

function validateRuntime(runtime, options) {
  const exact = ['nodeVersion', 'phpVersion', 'wordpressVersion', 'homeboyVersion', 'homeboySha256', 'homeboyExtensionsRef', 'wpCodeboxVersion', 'wpCodeboxSha256'];
  for (const key of exact) {
    const value = String(runtime[key] || '');
    assert(value && !/latest|unknown/i.test(value), `Runtime ${key} must be pinned.`);
  }
  assertSha(runtime.homeboyExtensionsRef, 'Homeboy Extensions ref');
  assert(/^[a-f0-9]{64}$/.test(runtime.homeboySha256), 'Homeboy archive SHA-256 is invalid.');
  assert(/^[a-f0-9]{64}$/.test(runtime.wpCodeboxSha256), 'WP Codebox archive SHA-256 is invalid.');
  assert(runtime.staticSiteImporterSha === options.staticSiteImporterSha, 'Runtime SSI SHA does not match the candidate.');
  assert(runtime.blocksEngineSha === options.blocksEngineSha, 'Runtime Blocks Engine SHA does not match the candidate.');
  assert(runtime.wpCodeboxSha === options.wpCodeboxSha, 'Runtime WP Codebox SHA does not match the candidate.');
}

function artifactManifest(files, root) {
  const seen = new Set();
  return files.map((file) => path.resolve(file)).filter((file) => !seen.has(file) && seen.add(file)).map((file) => {
    const relative = path.relative(root, file);
    assert(relative && !relative.startsWith('..') && !path.isAbsolute(relative), `Evidence file is outside artifact root: ${file}`);
    assert(fs.existsSync(file) && fs.statSync(file).isFile(), `Evidence file is missing: ${file}`);
    const content = fs.readFileSync(file);
    return { path: relative.split(path.sep).join('/'), sha256: crypto.createHash('sha256').update(content).digest('hex'), bytes: content.length };
  }).sort((left, right) => left.path.localeCompare(right.path));
}

function addRequiredFile(files, value, label, root) {
  assert(typeof value === 'string' && value, `${label} path is missing.`);
  const resolved = path.isAbsolute(value) ? value : path.join(root, value);
  const relative = path.relative(root, resolved);
  if (relative && !relative.startsWith('..') && !path.isAbsolute(relative)) {
    files.push(resolved);
    return;
  }
  if (fs.existsSync(resolved) && fs.statSync(resolved).isFile()) {
    const content = fs.readFileSync(resolved);
    const digest = crypto.createHash('sha256').update(content).digest('hex');
    const durable = path.join(root, 'runtime-evidence', `${digest}-${path.basename(value)}`);
    fs.mkdirSync(path.dirname(durable), { recursive: true });
    fs.writeFileSync(durable, content);
    files.push(durable);
    return;
  }
  const basename = path.basename(value);
  const matches = filesBelow(root).filter((file) => path.basename(file) === basename || path.basename(file).endsWith(`-${basename}`));
  assert(matches.length === 1, `${label} must resolve to exactly one durable evidence file; found ${matches.length}.`);
  files.push(matches[0]);
}

function filesBelow(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const file = path.join(directory, entry.name);
    return entry.isDirectory() ? filesBelow(file) : (entry.isFile() ? [file] : []);
  });
}

function normalizeOptions(input) {
  const options = { ...input };
  for (const key of ['matrixResult', 'registry', 'runtimeInputs', 'artifactRoot', 'staticSiteImporterSha', 'blocksEngineSha', 'wpCodeboxSha', 'fixtureTreeSha', 'solvedFixtureCount', 'solvedFixtureIds', 'runUrl', 'artifactUrl', 'output', 'manifestOutput']) {
    assert(options[key] !== undefined && options[key] !== '', `--${kebab(key)} is required.`);
  }
  options.solvedFixtureIds = String(options.solvedFixtureIds)
    .split(',')
    .map((value) => value.trim())
    .filter(Boolean);
  assert(options.solvedFixtureIds.length > 0, '--solved-fixture-ids must list at least one canonical solved fixture.');
  assert(new Set(options.solvedFixtureIds).size === options.solvedFixtureIds.length, '--solved-fixture-ids must be unique.');
  options.solvedFixtureIds.sort();
  options.artifactRoot = path.resolve(options.artifactRoot);
  options.output = path.resolve(options.output);
  options.manifestOutput = path.resolve(options.manifestOutput);
  assert(/^https:\/\/github\.com\/.+\/actions\/runs\/\d+$/.test(options.runUrl), 'Run URL must be a reviewer-resolvable GitHub Actions URL.');
  assert(/^https:\/\/github\.com\/.+\/actions\/runs\/\d+#artifacts$/.test(options.artifactUrl), 'Artifact URL must be a reviewer-resolvable GitHub Actions artifact URL.');
  return options;
}

function parseArgs(args) {
  const options = {};
  for (let index = 0; index < args.length; index += 1) {
    const arg = args[index];
    if (arg === '--help' || arg === '-h') return { help: true };
    if (!arg.startsWith('--')) continue;
    const [rawKey, inline] = arg.slice(2).split('=');
    options[camel(rawKey)] = inline === undefined ? args[++index] : inline;
  }
  return options;
}

function readJson(file, label) {
  try { return JSON.parse(fs.readFileSync(file, 'utf8')); } catch { throw new Error(`Could not read ${label}: ${file}`); }
}
function assertSha(value, label) { assert(/^[a-f0-9]{40}$/.test(String(value || '')), `${label} must be a full commit SHA.`); }
function assertFiniteMetric(object, key, fixtureId) {
  assert(Object.prototype.hasOwnProperty.call(object, key), `${fixtureId}: ${key} must be present.`);
  const value = object[key];
  const validNumber = typeof value === 'number' && Number.isFinite(value);
  const validNumericString = typeof value === 'string' && /^[+-]?(?:\d+(?:\.\d*)?|\.\d+)$/.test(value) && Number.isFinite(Number(value));
  assert(validNumber || validNumericString, `${fixtureId}: ${key} must be a finite number.`);
}
function assert(condition, message) { if (!condition) throw new Error(message); }
function camel(value) { return value.replace(/-([a-z])/g, (_match, letter) => letter.toUpperCase()); }
function kebab(value) { return value.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`); }

function printHelp() {
  process.stdout.write('Usage: node tools/verify-solved-site-promotion.mjs --matrix-result <file> --registry <file> --runtime-inputs <file> --artifact-root <dir> --static-site-importer-sha <sha> --blocks-engine-sha <sha> --wp-codebox-sha <sha> --fixture-tree-sha <sha> --solved-fixture-count <n> --solved-fixture-ids <id1,id2,...> --run-url <url> --artifact-url <url> --output <file> --manifest-output <file>\n');
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    const options = parseArgs(process.argv.slice(2));
    if (options.help) printHelp(); else verifySolvedSitePromotion(options);
  } catch (error) {
    process.stderr.write(`${error.message}\n`);
    process.exitCode = 1;
  }
}
