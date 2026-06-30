// Fixture discovery, normalization, and taxonomy classification for the
// Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).

import fs from 'node:fs';
import path from 'node:path';

import {
  FIXTURE_MATRIX_SCHEMA,
  FIXTURE_CLASSES,
  FIXTURE_MANIFEST_FILENAME,
  FIXTURE_COMPLEXITY_MIN,
  FIXTURE_COMPLEXITY_MAX,
} from './shared/constants.mjs';
import {
  normalizeArray,
  finiteNumber,
  requiredDirectory,
  slug,
  fileType,
} from './shared/utils.mjs';

export function discoverFixtures(root, options = {}) {
  const fixtureRoot = requiredDirectory(root || options.fixtureRoot || options.fixture_root, 'fixtureRoot');
  const entrypoint = options.entrypoint || 'index.html';
  const maxDepth = finiteNumber(options.maxDepth ?? options.max_depth, 2);
  const fixtures = [];

  visitFixtureDirectory(fixtureRoot, 0, maxDepth, (directory) => {
    const entryPath = path.join(directory, entrypoint);
    if (!fs.existsSync(entryPath) || !fs.statSync(entryPath).isFile()) {
      return;
    }

    fixtures.push(normalizeFixture({ root: fixtureRoot, directory, entrypoint }));
  });

  return fixtures.sort((left, right) => left.id.localeCompare(right.id));
}

export function createFixtureMatrix(input = {}) {
  const normalized = normalizeArray(input.fixtures || discoverFixtures(input.fixture_root || input.fixtureRoot, input))
    .map((fixture) => normalizeFixture(fixture));
  const filter = normalizeFixtureFilter(input);
  const fixtures = filter ? normalized.filter((fixture) => fixtureMatchesFilter(fixture, filter)) : normalized;
  return {
    schema: FIXTURE_MATRIX_SCHEMA,
    id: input.id || input.run_id || input.runId || 'static-site-importer-fixture-matrix',
    fixture_root: input.fixture_root || input.fixtureRoot || fixtures[0]?.fixture_root || normalized[0]?.fixture_root || '',
    entrypoint: input.entrypoint || 'index.html',
    ...(filter ? { filter } : {}),
    count: fixtures.length,
    fixtures,
    artifacts: {
      result: input.result_artifact || input.resultArtifact || 'static-site-fixture-matrix-result.json',
      summary: input.summary_artifact || input.summaryArtifact || 'summary.json',
      findings: input.findings_artifact || input.findingsArtifact || 'finding-packets.json',
    },
  };
}

// Classify a fixture. The per-fixture `fixture.json` manifest is the SOLE source
// of truth for `class` — there is no heuristic fallback. Resolution order:
//   1. An explicit class injected by tests / the runner / a carried result.
//   2. The fixture's manifest `class` (must be a verbatim FIXTURE_CLASSES value).
//   3. `unknown` — emitted with a loud warning naming the fixture.
// A missing manifest or an invalid `class` value does NOT crash the run: the
// single offending fixture resolves to `unknown` and is flagged.
export function classifyFixture(input = {}) {
  const explicit = normalizeFixtureClass(input.fixture_class || input.class);
  if (explicit && explicit !== 'unknown') {
    return { fixture_class: explicit, signals: ['explicit_metadata'], warning: null };
  }

  const fixtureName = fixtureLabelFor(input);
  const manifest = input.manifest !== undefined
    ? input.manifest
    : readFixtureManifest(input.directory || input.path || input.fixture_path || input.fixturePath);

  if (!manifest || typeof manifest !== 'object') {
    const warning = `Fixture "${fixtureName}" has no ${FIXTURE_MANIFEST_FILENAME} manifest; classifying as "unknown".`;
    warnFixtureClassification(warning);
    return { fixture_class: 'unknown', signals: ['manifest_missing'], warning };
  }

  const rawClass = manifest.class ?? manifest.fixture_class;
  if (typeof rawClass !== 'string' || !FIXTURE_CLASSES.includes(rawClass)) {
    const warning = `Fixture "${fixtureName}" ${FIXTURE_MANIFEST_FILENAME} has invalid class ${JSON.stringify(rawClass)}; expected one of ${FIXTURE_CLASSES.join(', ')}. Classifying as "unknown".`;
    warnFixtureClassification(warning);
    return { fixture_class: 'unknown', signals: ['manifest_invalid_class'], warning };
  }

  return { fixture_class: rawClass, signals: ['manifest'], warning: null };
}

function fixtureLabelFor(input = {}) {
  return input.id || input.slug || input.label || input.name || input.directory || input.path || input.fixture_path || 'unknown';
}

// Loud, single-line warning to stderr so a missing/invalid manifest is impossible
// to miss in run logs. Exported as a no-arg-overridable hook so tests can capture
// the emitted warnings deterministically.
export function warnFixtureClassification(message) {
  process.stderr.write(`[fixture-matrix] WARNING: ${message}\n`);
}

// Read `<fixture-dir>/fixture.json` if present. Returns the parsed object, or
// null when the manifest is absent or unparseable (an unparseable manifest is
// warned about and treated as missing — fail loud, do not guess).
export function readFixtureManifest(directory) {
  if (!directory) {
    return null;
  }
  const manifestPath = path.join(directory, FIXTURE_MANIFEST_FILENAME);
  if (!fs.existsSync(manifestPath) || !fs.statSync(manifestPath).isFile()) {
    return null;
  }
  try {
    const parsed = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
    return parsed && typeof parsed === 'object' ? parsed : null;
  } catch (error) {
    warnFixtureClassification(`Failed to parse ${manifestPath}: ${error.message}. Treating manifest as absent.`);
    return null;
  }
}

// Normalize a manifest `tags` value into a clean string array.
export function normalizeManifestTags(value) {
  return normalizeArray(value)
    .map((tag) => String(tag || '').trim())
    .filter(Boolean);
}

// Normalize a manifest `complexity` value into an integer within bounds, or null.
export function normalizeManifestComplexity(value) {
  if (value === undefined || value === null || value === '') {
    return null;
  }
  const number = Number(value);
  if (!Number.isFinite(number)) {
    return null;
  }
  const clamped = Math.min(FIXTURE_COMPLEXITY_MAX, Math.max(FIXTURE_COMPLEXITY_MIN, Math.round(number)));
  return clamped;
}

// Build the active class/tag filter from runner/bench/test options, or null when
// no filter is requested. Supports a single class and one-or-more tags.
function normalizeFixtureFilter(input = {}) {
  const classValue = normalizeFixtureClass(input.class || input.fixture_class || input.fixtureClass);
  const fixtureClass = classValue && classValue !== 'unknown' ? classValue
    : ((input.class || input.fixture_class || input.fixtureClass) ? 'unknown' : '');
  const tags = normalizeManifestTags(input.tag || input.tags).map((tag) => tag.toLowerCase());
  if (!fixtureClass && tags.length === 0) {
    return null;
  }
  return { ...(fixtureClass ? { fixture_class: fixtureClass } : {}), ...(tags.length ? { tags } : {}) };
}

function fixtureMatchesFilter(fixture, filter) {
  if (filter.fixture_class && fixture.fixture_class !== filter.fixture_class) {
    return false;
  }
  if (filter.tags && filter.tags.length > 0) {
    const fixtureTags = normalizeManifestTags(fixture.tags).map((tag) => tag.toLowerCase());
    if (!filter.tags.every((tag) => fixtureTags.includes(tag))) {
      return false;
    }
  }
  return true;
}

export function normalizeFixture(input) {
  const directory = requiredDirectory(input.directory || input.path || input.fixture_path || input.fixturePath, 'fixture.directory');
  const root = input.root || input.fixture_root || input.fixtureRoot || path.dirname(directory);
  const relative = path.relative(path.resolve(root), path.resolve(directory));
  const id = slug(input.id || input.slug || (relative && !relative.startsWith('..') ? relative : path.basename(directory)));
  const files = input.files || input.fixture_files || input.fixtureFiles || collectFixtureFiles(directory, { maxFiles: input.maxFiles || input.max_files || 1000 });
  const manifest = input.manifest !== undefined ? input.manifest : readFixtureManifest(directory);
  const taxonomy = normalizeFixtureTaxonomy(input.taxonomy) || classifyFixture({ ...input, id, directory, root, files, manifest });
  const tags = normalizeManifestTags(manifest?.tags ?? input.tags);
  const complexity = normalizeManifestComplexity(manifest?.complexity ?? input.complexity);
  return {
    id,
    label: input.label || input.name || id,
    directory,
    fixture_path: directory,
    fixture_root: root,
    entrypoint: input.entrypoint || 'index.html',
    fixture_class: taxonomy.fixture_class,
    tags,
    complexity,
    taxonomy: {
      ...taxonomy,
      tags,
      complexity,
    },
  };
}

function normalizeFixtureTaxonomy(taxonomy) {
  if (!taxonomy || typeof taxonomy !== 'object') {
    return null;
  }
  const fixtureClassValue = taxonomy.fixture_class || taxonomy.fixtureClass;
  if (!fixtureClassValue) {
    return null;
  }
  return {
    fixture_class: normalizeFixtureClass(fixtureClassValue) || 'unknown',
    signals: normalizeArray(taxonomy.signals),
    warning: taxonomy.warning || null,
  };
}

export function collectFixtureFiles(directory, options = {}) {
  const maxFiles = finiteNumber(options.maxFiles ?? options.max_files, 1000);
  const files = [];
  const visit = (current) => {
    for (const entry of fs.readdirSync(current, { withFileTypes: true })) {
      if (entry.name === '.git' || entry.name === 'node_modules') {
        continue;
      }
      // The per-fixture manifest is matrix metadata, not website source — never
      // pack it into the imported site artifact.
      if (entry.isFile() && entry.name === FIXTURE_MANIFEST_FILENAME) {
        continue;
      }
      const entryPath = path.join(current, entry.name);
      if (entry.isDirectory()) {
        visit(entryPath);
        continue;
      }
      if (!entry.isFile()) {
        continue;
      }
      const relativePath = path.relative(directory, entryPath).replace(/\\/g, '/');
      const stat = fs.statSync(entryPath);
      files.push({ relative_path: relativePath, absolute_path: entryPath, type: fileType(relativePath), bytes: stat.size });
      if (files.length > maxFiles) {
        throw new Error(`Fixture ${directory} has more than ${maxFiles} files.`);
      }
    }
  };
  visit(directory);
  return files.sort((left, right) => left.relative_path.localeCompare(right.relative_path));
}

function visitFixtureDirectory(directory, depth, maxDepth, callback) {
  callback(directory);
  if (depth >= maxDepth) {
    return;
  }
  for (const entry of fs.readdirSync(directory, { withFileTypes: true })) {
    if (entry.isDirectory() && entry.name !== '.git' && entry.name !== 'node_modules') {
      visitFixtureDirectory(path.join(directory, entry.name), depth + 1, maxDepth, callback);
    }
  }
}

export function normalizeFixtureClass(value) {
  const normalized = String(value || '').trim().toLowerCase().replace(/[_\s-]+/g, '/');
  const aliases = {
    marketing: 'marketing/static',
    static: 'marketing/static',
    marketingstatic: 'marketing/static',
    'marketing/static': 'marketing/static',
    docs: 'docs/blog',
    documentation: 'docs/blog',
    blog: 'docs/blog',
    'docs/blog': 'docs/blog',
    ecommerce: 'ecommerce/catalog',
    commerce: 'ecommerce/catalog',
    catalog: 'ecommerce/catalog',
    shop: 'ecommerce/catalog',
    'ecommerce/catalog': 'ecommerce/catalog',
    app: 'app/dashboard',
    dashboard: 'app/dashboard',
    'app/dashboard': 'app/dashboard',
    canvas: 'canvas/webgl/audio/runtime-heavy',
    webgl: 'canvas/webgl/audio/runtime-heavy',
    audio: 'canvas/webgl/audio/runtime-heavy',
    runtime: 'canvas/webgl/audio/runtime-heavy',
    'runtime/heavy': 'canvas/webgl/audio/runtime-heavy',
    'canvas/webgl/audio/runtime/heavy': 'canvas/webgl/audio/runtime-heavy',
    'canvas/webgl/audio/runtime-heavy': 'canvas/webgl/audio/runtime-heavy',
  };
  return aliases[normalized] || (FIXTURE_CLASSES.includes(normalized) ? normalized : 'unknown');
}

export function fixtureClassRank(value) {
  const index = FIXTURE_CLASSES.indexOf(value);
  return index >= 0 ? index : FIXTURE_CLASSES.length;
}
