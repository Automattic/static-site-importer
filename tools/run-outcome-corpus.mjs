#!/usr/bin/env node
import fs from 'node:fs';
import path from 'node:path';
import { pathToFileURL, fileURLToPath } from 'node:url';
import { runUrlLoopIntake, runUrlLoopMatrix } from './url-loop-intake.mjs';
import { materializeGeneratedArtifactFixtures } from '../lib/artifact-intake.mjs';
import { runExistingRuntimeAcceptance } from '../lib/run-existing-runtime-acceptance.mjs';
import { unavailableOutcomes } from '../lib/outcome-checks.mjs';
import { outcomeMatrix, outcomeMatrixMarkdown } from '../lib/outcome-matrix.mjs';

const write = (file, value) => { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, `${JSON.stringify(value, null, 2)}\n`); };

export function validateCorpus(manifest) {
  if (manifest?.schema !== 'static-site-importer/outcome-corpus/v1' || !Array.isArray(manifest.sites) || !manifest.sites.length) throw new Error('A nonempty outcome-corpus/v1 manifest is required');
  if (Object.keys(manifest).some(key => !['schema', 'sites'].includes(key))) throw new Error('Unknown corpus property');
  const ids = new Set();
  for (const site of manifest.sites) {
    if (!site || typeof site !== 'object' || Object.keys(site).some(key => !['id', 'source_url', 'artifact_directory', 'routes', 'expected_decisions'].includes(key))) throw new Error('Unknown corpus entry property');
    if (!/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/.test(site.id ?? '') || ids.has(site.id)) throw new Error('Invalid or duplicate corpus id');
    ids.add(site.id);
    if (Boolean(site.source_url) === Boolean(site.artifact_directory)) throw new Error(`${site.id}: choose source_url or artifact_directory`);
    if (site.source_url && (typeof site.source_url !== 'string' || !/^https?:$/.test(new URL(site.source_url).protocol))) throw new Error('HTTP(S) source required');
    if (site.artifact_directory && typeof site.artifact_directory !== 'string') throw new Error('Artifact directory must be a string');
    if (site.routes && (!Array.isArray(site.routes) || !site.routes.length || site.routes.some(route => typeof route !== 'string' || !/^\/(?!\/)/.test(route)))) throw new Error('Invalid route scope');
    if (site.routes && new Set(site.routes).size !== site.routes.length) throw new Error('Duplicate route');
    if (site.expected_decisions && !Array.isArray(site.expected_decisions)) throw new Error('Expected decisions must be an array');
    for (const decision of site.expected_decisions ?? []) if (Object.keys(decision).some(key => !['route', 'reason', 'expected', 'actual'].includes(key)) || !/^\/(?!\/)/.test(decision.route ?? '') || typeof decision.reason !== 'string' || !decision.reason.trim() || !decision.expected || !decision.actual || Array.isArray(decision.expected) || Array.isArray(decision.actual) || typeof decision.expected !== 'object' || typeof decision.actual !== 'object') throw new Error('Exact expected decision and reason required');
  }
  return manifest;
}

/** Toolchain supplies runtime credentials and lifecycle, not check results. */
export async function runOutcomeCorpus({ manifest: manifestPath, output, compare, toolchain, producerRepository, producerVersion, producerTarball }) {
  const manifest = validateCorpus(JSON.parse(fs.readFileSync(manifestPath, 'utf8')));
  const root = path.resolve(output);
  if (fs.existsSync(root) && fs.readdirSync(root).length) throw new Error('Output must be a fresh private directory');
  toolchain ??= await (await import('../lib/default-outcome-toolchain.mjs')).createDefaultToolchain({ output, producerRepository, producerVersion, producerTarball });
  for (const name of ['capture', 'ssi', 'blocks_engine']) {
    const identity = toolchain.identities?.[name];
    if (!identity?.version || (!(name === 'blocks_engine' && identity.commit === 'unverified' && identity.artifact_identity) && !/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/i.test(identity.commit ?? ''))) throw new Error(`Exact ${name} version and commit required`);
  }
  fs.mkdirSync(root, { recursive: true });
  const imports = [];
  for (const site of manifest.sites) {
    const directory = path.join(root, site.id);
    const evidence = `${site.id}/report.json`;
    let report = unavailableOutcomes('', site.routes ?? [], site.id);
    let runtime;
    let failure;
    try {
      let imported;
      if (toolchain.importSite) {
        imported = await toolchain.importSite({ site, directory, manifestPath });
      } else if (site.source_url) {
        imported = await runUrlLoopIntake({ url: site.source_url, outputRoot: path.join(directory, 'intake'), capture: toolchain.capture, staticSiteImporter: toolchain.staticSiteImporter, blocksEngine: toolchain.blocksEngine, ssiIdentity: toolchain.identities.ssi, blocksEngineIdentity: toolchain.identities.blocks_engine, runMatrix: true, matrixArgs: toolchain.matrixArgs });
      } else {
        const artifactRoot = path.resolve(path.dirname(path.resolve(manifestPath)), site.artifact_directory);
        const fixtureRoot = path.join(directory, 'fixtures', 'websites');
        const intake = materializeGeneratedArtifactFixtures({ artifactRoot, fixtureRoot });
        if (intake.count !== 1) throw new Error('Each corpus entry must contain exactly one captured site');
        imported = await runUrlLoopMatrix({ fixtureRoot, fixtureId: intake.fixtures[0].id, staticSiteImporter: toolchain.staticSiteImporter, blocksEngine: toolchain.blocksEngine, ssiIdentity: toolchain.identities.ssi, blocksEngineIdentity: toolchain.identities.blocks_engine, matrixArgs: toolchain.matrixArgs, output: path.join(directory, 'import', 'homeboy-bench-result.json') });
      }
      write(path.join(directory, 'import.json'), imported);
      if (imported.failures?.length) throw new Error('Capture/import failed; see import.json');
      runtime = await toolchain.openRuntime({ site, directory, imported });
      const config = runtime.config;
      config.outputDirectory = path.join(directory, 'acceptance');
      config.importRunId = site.id;
      config.outcomes ??= {};
      for (const decision of site.expected_decisions ?? []) {
        const document = config.outcomes.documents?.[decision.route];
        if (!document) throw new Error('Decision route missing from captured documents');
        document.decisions = [...(document.decisions ?? []), { reason: decision.reason, expected: decision.expected, actual: decision.actual }];
      }
      if (site.routes) {
        config.manifest = path.join(directory, 'routes.json');
        write(config.manifest, { routes: site.routes });
      }
      const acceptance = await runExistingRuntimeAcceptance(config);
      report = JSON.parse(fs.readFileSync(path.join(config.outputDirectory, acceptance.artifacts.outcomes.path), 'utf8'));
    } catch (error) { failure = error.message; }
    finally {
      try { await runtime?.close?.(); } catch (error) { failure = `runtime_cleanup_failed: ${error.message}`; }
    }
    write(path.join(root, evidence), { schema: 'static-site-importer/outcome-corpus-import/v1', id: site.id, versions: toolchain.identities, outcomes: report, ...(failure ? { failure } : {}) });
    imports.push({ id: site.id, report, evidence: `${evidence}#/outcomes`, reason: failure });
  }
  const previous = compare ? JSON.parse(fs.readFileSync(compare, 'utf8')) : undefined;
  const matrix = outcomeMatrix(imports, previous);
  if (imports.some(row => row.reason)) matrix.status = 'failed';
  matrix.versions = toolchain.identities;
  matrix.identity_warnings = [
    ...(toolchain.identities.ssi.dirty ? ['SSI checkout is dirty; this run is not clean release proof.'] : []),
    ...(toolchain.identities.blocks_engine.commit === 'unverified' ? ['Blocks Engine installed artifact carries no verified commit identity; file upstream for artifact provenance.'] : []),
  ];
  write(path.join(root, 'matrix.json'), matrix);
  fs.writeFileSync(path.join(root, 'matrix.md'), `${outcomeMatrixMarkdown(matrix)}\n${matrix.identity_warnings.join('\n')}\n`);
  return matrix;
}

if (process.argv[1] === fileURLToPath(import.meta.url)) {
  try {
    const args = process.argv.slice(2), options = {};
    for (let index = 0; index < args.length; index += 2) {
      if (!['--manifest', '--output', '--compare', '--producer-repository', '--producer-version', '--producer-tarball'].includes(args[index]) || !args[index + 1]) throw new Error('Usage: run-outcome-corpus.mjs --manifest <path> --output <dir> (--producer-repository <owner/repo> [--producer-version <v>] | --producer-tarball <path>) [--compare <matrix.json>]');
      options[args[index].slice(2).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase())] = args[index + 1];
    }
    if (!options.manifest || !options.output) throw new Error('Declare --manifest and --output');
    const toolchain = process.env.SSI_OUTCOME_TOOLCHAIN ? (await import(pathToFileURL(path.resolve(process.env.SSI_OUTCOME_TOOLCHAIN)).href)).default : undefined;
    const result = await runOutcomeCorpus({ ...options, toolchain });
    if (result.status !== 'passed') process.exitCode = 1;
  } catch (error) { console.error(error.message); process.exitCode = 1; }
}
