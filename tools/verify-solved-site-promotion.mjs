#!/usr/bin/env node

import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

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
  assertSha(options.fixtureTreeSha, 'Fixture tree SHA');
  assert(Number(options.solvedFixtureCount) > 0, 'Solved fixture corpus must be non-empty.');
  assert(matrix.summary?.execution_status === 'requested', 'Matrix execution was not requested.');
  assert(matrix.summary?.generation_status === 'succeeded', 'Matrix generation did not succeed.');
  assert(Array.isArray(matrix.fixtures) && matrix.fixtures.length > 0, 'Selected fixture corpus must be non-empty.');
  assert(matrix.summary?.fixture_count === matrix.fixtures.length, 'Matrix fixture count is inconsistent.');
  assert(matrix.summary?.failed === 0 && matrix.summary?.not_run === 0, 'Every selected fixture must pass execution.');
  assert(matrix.summary?.solved_candidate_gate?.enabled === true, 'Solved-candidate gate must be enabled.');
  assert(matrix.summary?.solved_candidate_gate?.failed_fixture_count === 0, 'Solved-candidate gate reported failures.');

  validateRuntime(runtime, options);
  const decisions = new Map((registry.fixture_decisions || []).map((row) => [row.fixture_id, row]));
  const requiredFiles = [options.matrixResult, options.registry];
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
      solved_fixture_count: Number(options.solvedFixtureCount),
      selected_fixture_ids: matrix.fixtures.map((fixture) => fixture.fixture_id).sort(),
      selected_fixture_count: matrix.fixtures.length,
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
    assert(Number(quality[key] || 0) === 0, `${id}: ${key} must be zero.`);
  }
  const composition = fixture.block_composition || {};
  assert(Number(composition.block_total) > 0, `${id}: imported block count must be nonzero.`);
  assert(Number(composition.native_block_count) === Number(composition.block_total), `${id}: every imported block must be native.`);
  assert(Number(composition.core_html_block_count || 0) === 0, `${id}: core/html blocks are forbidden.`);
  const editor = fixture.editor_validation || {};
  assert(editor.validation_method === 'wp.blocks.validateBlock', `${id}: editor validation must use wp.blocks.validateBlock.`);
  assert(Number(editor.total_blocks) > 0 && editor.valid_blocks === editor.total_blocks && Number(editor.invalid_blocks) === 0, `${id}: editor validation is incomplete or invalid.`);
  assert(fixture.editor_canvas?.status === 'captured', `${id}: editor canvas evidence is missing.`);
  addRequiredFile(requiredFiles, fixture.editor_canvas?.screenshot, `${id}: editor screenshot`, options.artifactRoot);
  const visual = fixture.visual_parity_artifacts || {};
  assert(Number(visual.metrics?.mismatch_ratio) === 0 && Number(visual.metrics?.mismatch_pixels) === 0, `${id}: visual mismatch must be exactly zero.`);
  for (const slot of ['source_screenshot', 'imported_screenshot', 'diff_screenshot', 'visual_diff']) {
    const artifact = visual.artifacts?.[slot];
    assert(artifact?.status === 'captured', `${id}: ${slot} evidence is missing.`);
    addRequiredFile(requiredFiles, artifact?.ref?.path, `${id}: ${slot}`, options.artifactRoot);
  }
  const evidence = fixture.matrix_evidence || {};
  assert(evidence.readiness === 'verified' && (evidence.missing || []).length === 0, `${id}: runtime evidence is incomplete.`);
  assert(evidence.materialization_receipt?.status === 'completed' && evidence.materialization_receipt?.plan_hash, `${id}: completed materialization receipt is missing.`);
  assert(evidence.transformer?.package_reference === options.blocksEngineSha, `${id}: transformer provenance does not match the Blocks Engine candidate.`);
  assert(Number(fixture.editor_quality?.native_conversion_rate) === 1, `${id}: native conversion rate must equal 1.`);
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
  for (const key of ['matrixResult', 'registry', 'runtimeInputs', 'artifactRoot', 'staticSiteImporterSha', 'blocksEngineSha', 'fixtureTreeSha', 'solvedFixtureCount', 'runUrl', 'artifactUrl', 'output', 'manifestOutput']) {
    assert(options[key] !== undefined && options[key] !== '', `--${kebab(key)} is required.`);
  }
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
function assert(condition, message) { if (!condition) throw new Error(message); }
function camel(value) { return value.replace(/-([a-z])/g, (_match, letter) => letter.toUpperCase()); }
function kebab(value) { return value.replace(/[A-Z]/g, (letter) => `-${letter.toLowerCase()}`); }

function printHelp() {
  process.stdout.write('Usage: node tools/verify-solved-site-promotion.mjs --matrix-result <file> --registry <file> --runtime-inputs <file> --artifact-root <dir> --static-site-importer-sha <sha> --blocks-engine-sha <sha> --fixture-tree-sha <sha> --solved-fixture-count <n> --run-url <url> --artifact-url <url> --output <file> --manifest-output <file>\n');
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
