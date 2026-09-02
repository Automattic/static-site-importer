// Fixture discovery, normalization, and taxonomy classification for the
// Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).
/**
 * External dependencies
 */
import fs from 'node:fs';
import path from 'node:path';

/**
 * Internal dependencies
 */
import {
  FIXTURE_MATRIX_SCHEMA,
  FIXTURE_CLASSES,
  FIXTURE_MANIFEST_FILENAME,
  GENERATED_ARTIFACT_METADATA_FILENAME,
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

// This mirrors SSI's public content-only intake contract. The matrix must only
// construct artifacts that can enter that public boundary.
const STATIC_IMPORT_EXTENSIONS = new Set([
  'html', 'htm', 'css', 'js', 'mjs', 'json', 'map', 'xml', 'txt', 'md', 'markdown',
  'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp',
  'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp3', 'mp4', 'webm', 'ogg', 'wav', 'pdf',
]);

export function discoverFixtures(root, options = {}) {
  const requestedRoot = root || options.fixtureRoot || options.fixture_root;
  const fixtureRoot = path.resolve(requestedRoot || '.');
  const rootInspection = inspectFixtureDirectories(fixtureRoot, options);
  if (rootInspection.exclusions[0]?.reason && ['root_missing', 'root_not_directory', 'root_symlink'].includes(rootInspection.exclusions[0].reason)) {
    return [];
  }
  const searchRoots = resolveFixtureSearchRoots(fixtureRoot);
  const entrypoint = options.entrypoint || 'index.html';
  const maxDepth = finiteNumber(options.maxDepth ?? options.max_depth, 2);
  const candidates = [];

  for (const searchRoot of searchRoots) {
    // Discovery and preflight consume the same eligibility inventory. This keeps
    // malformed metadata and colliding stable IDs out of executable recipes.
    const inspection = inspectFixtureDirectories(searchRoot, { entrypoint, maxDepth });
    for (const fixture of inspection.eligible) {
      candidates.push({ searchRoot, fixture });
    }
  }

  const counts = new Map();
  for (const candidate of candidates) {
    counts.set(candidate.fixture.id, (counts.get(candidate.fixture.id) || 0) + 1);
  }
  const fixtures = candidates
    // Stable IDs identify artifact/result directories, so they must be unique
    // across the active and solved corpora before any lane can select them.
    .filter((candidate) => counts.get(candidate.fixture.id) === 1)
    .map(({ searchRoot, fixture }) => normalizeFixture({ root: searchRoot, directory: fixture.directory, entrypoint, fixture_corpus: corpusLabelForSearchRoot(searchRoot, fixtureRoot) }));
  return fixtures.sort((left, right) => left.id.localeCompare(right.id));
}

// This is the executable-fixture predicate used by discovery and operator
// preflight: a real directory with a real file entrypoint. Symlinked fixture
// directories are intentionally excluded because recursive discovery skips them.
export function isExecutableFixtureDirectory(directory, entrypoint = 'index.html') {
  try {
    return fs.lstatSync(directory).isDirectory() && fs.lstatSync(path.join(directory, entrypoint)).isFile();
  } catch {
    return false;
  }
}

// Inspect one corpus level without throwing so operator dry-runs can explain an
// empty plan before an execution lane is rejected.
export function inspectFixtureDirectories(root, options = {}) {
  const entrypoint = options.entrypoint || 'index.html';
  const limit = finiteNumber(options.limit, 50);
  const maxDepth = finiteNumber(options.maxDepth ?? options.max_depth, 2);
  const requestedRoot = path.resolve(root || '.');
  const selected_ids = [];
  const exclusions = [];
  const diagnostics = [];
  const candidates = [];
  const addExclusion = (id, reason) => {
    exclusions.push({ id, reason });
  };
  try {
    if (fs.lstatSync(requestedRoot).isSymbolicLink()) {
      addExclusion('', 'root_symlink');
      return emptyFixtureInspection(requestedRoot, entrypoint, selected_ids, exclusions, diagnostics);
    }
    if (!fs.lstatSync(requestedRoot).isDirectory()) {
      addExclusion('', 'root_not_directory');
      return emptyFixtureInspection(requestedRoot, entrypoint, selected_ids, exclusions, diagnostics);
    }
  } catch {
    addExclusion('', 'root_missing');
    return emptyFixtureInspection(requestedRoot, entrypoint, selected_ids, exclusions, diagnostics);
  }
  const inspect = (directory, depth) => {
    const id = slug(path.relative(requestedRoot, directory));
    if (isExecutableFixtureDirectory(directory, entrypoint)) {
      selected_ids.push(id);
      const manifestPath = path.join(directory, FIXTURE_MANIFEST_FILENAME);
      let metadataReason = '';
      if (fs.existsSync(manifestPath)) {
        try {
          const manifest = JSON.parse(fs.readFileSync(manifestPath, 'utf8'));
          if (!manifest || typeof manifest !== 'object' || Array.isArray(manifest)) {
            metadataReason = 'metadata_not_object';
          } else if (manifest.fixture_class !== undefined || manifest.class !== undefined) {
            const fixtureClass = manifest.fixture_class ?? manifest.class;
            if (typeof fixtureClass !== 'string' || !FIXTURE_CLASSES.includes(fixtureClass)) {
              metadataReason = 'metadata_invalid_class';
            }
          }
        } catch {
          metadataReason = 'malformed_metadata';
        }
      }
      if (metadataReason && diagnostics.length < limit) diagnostics.push({ id, reason: metadataReason });
      candidates.push({ id, directory, reason: metadataReason });
    }
    const entries = fs.readdirSync(directory, { withFileTypes: true }).filter((entry) => !entry.name.startsWith('.'));
    const childDirectories = entries.filter((entry) => entry.isDirectory());
    for (const entry of entries) {
      const child = path.join(directory, entry.name);
      const childId = slug(path.relative(requestedRoot, child));
      if (entry.isSymbolicLink()) addExclusion(childId, 'symlink');
      else if (!entry.isDirectory()) continue;
      else if (depth < maxDepth) inspect(child, depth + 1);
      else if (!isExecutableFixtureDirectory(child, entrypoint)) addExclusion(childId, 'missing_entrypoint');
    }
    if (directory !== requestedRoot && !isExecutableFixtureDirectory(directory, entrypoint) && childDirectories.length === 0) {
      addExclusion(id, 'missing_entrypoint');
    }
  };
  inspect(requestedRoot, 0);
  const candidateCounts = new Map();
  for (const candidate of candidates) {
    candidateCounts.set(candidate.id, (candidateCounts.get(candidate.id) || 0) + 1);
  }
  const duplicateIds = new Set([...candidateCounts].filter(([, count]) => count > 1).map(([id]) => id));
  const malformed = candidates.filter((candidate) => candidate.reason)
    .map(({ id, directory, reason }) => ({ id, directory, reason }))
    .sort(compareInventoryRows);
  const duplicates = candidates.filter((candidate) => duplicateIds.has(candidate.id))
    .map(({ id, directory }) => ({ id, directory, reason: 'duplicate_id' }))
    .sort(compareInventoryRows);
  const eligible = candidates.filter((candidate) => !candidate.reason && !duplicateIds.has(candidate.id))
    .map(({ id, directory }) => ({ id, directory, reason: 'eligible' }))
    .sort(compareInventoryRows);
  return {
    root: requestedRoot,
    entrypoint,
    selected_ids: selected_ids.sort(),
    executable_count: candidates.length,
    eligible_ids: eligible.map((candidate) => candidate.id),
    eligible,
    malformed,
    duplicates,
    exclusions,
    diagnostics,
  };
}

function compareInventoryRows(left, right) {
  return left.id.localeCompare(right.id) || left.directory.localeCompare(right.directory);
}

function emptyFixtureInspection(root, entrypoint, selected_ids, exclusions, diagnostics) {
  return { root, entrypoint, selected_ids, executable_count: 0, eligible_ids: [], eligible: [], malformed: [], duplicates: [], exclusions, diagnostics };
}

// Resolve the actual directories to search for fixtures. If the requested root
// contains a `websites/` subdirectory, treat it as a Blocks Engine corpus parent
// and search both `websites/` (active corpus) and `solved/` (regression corpus).
// This keeps `--fixture-root <blocks-engine>/fixtures` working while discovering
// solved regression fixtures automatically. Any other root is searched as-is.
export function resolveFixtureSearchRoots(fixtureRoot) {
  const websitesRoot = path.join(fixtureRoot, 'websites');
  if (!isRealDirectory(websitesRoot)) {
    return [fixtureRoot];
  }
  const roots = [websitesRoot];
  const solvedRoot = path.join(fixtureRoot, 'solved');
  if (isRealDirectory(solvedRoot)) {
    roots.push(solvedRoot);
  }
  return roots;
}

function isRealDirectory(directory) {
  try {
    return fs.lstatSync(directory).isDirectory();
  } catch {
    return false;
  }
}

function corpusLabelForSearchRoot(searchRoot, fixtureRoot) {
  const normalizedSearchRoot = path.resolve(searchRoot);
  const normalizedFixtureRoot = path.resolve(fixtureRoot);
  if (normalizedSearchRoot === path.join(normalizedFixtureRoot, 'solved')) {
    return 'solved';
  }
  return 'active';
}

export function createFixtureMatrix(input = {}) {
  const requestedRoot = input.fixture_root || input.fixtureRoot || '';
  const normalized = normalizeArray(input.fixtures || discoverFixtures(requestedRoot, input))
    .map((fixture) => normalizeFixture(fixture));
  const filter = normalizeFixtureFilter(input);
  const fixtures = filter ? normalized.filter((fixture) => fixtureMatchesFilter(fixture, filter)) : normalized;
  const corpusLayout = requestedRoot ? detectCorpusLayout(requestedRoot) : null;
  const fixtureCoverage = requestedRoot ? buildFixtureCoverage(requestedRoot, input, fixtures, filter) : null;
  return {
    schema: FIXTURE_MATRIX_SCHEMA,
    id: input.id || input.run_id || input.runId || 'static-site-importer-fixture-matrix',
    fixture_root: corpusLayout ? corpusLayout.fixture_root : (requestedRoot || fixtures[0]?.fixture_root || normalized[0]?.fixture_root || ''),
    ...(corpusLayout ? { fixture_directories: corpusLayout.fixture_directories } : {}),
    entrypoint: input.entrypoint || 'index.html',
    ...(filter ? { filter } : {}),
    count: fixtures.length,
    manifest_coverage: fixtureManifestCoverage(fixtures),
    ...(fixtureCoverage ? { fixture_coverage: fixtureCoverage } : {}),
    fixtures,
    artifacts: {
      result: input.result_artifact || input.resultArtifact || 'static-site-fixture-matrix-result.json',
      summary: input.summary_artifact || input.summaryArtifact || 'summary.json',
      findings: input.findings_artifact || input.findingsArtifact || 'finding-packets.json',
    },
  };
}

// This inventory is the single corpus contract shared by discovery, operator
// preflight, and result validation. It is intentionally derived from fixture
// directories and their authored metadata rather than a separately maintained
// fixture count.
export function buildFixtureCoverage(root, options = {}, selectedFixtures = [], filter = null) {
  const fixtureRoot = path.resolve(root || '.');
  const rootInspection = inspectFixtureDirectories(fixtureRoot, options);
  if (rootInspection.exclusions[0]?.reason && ['root_missing', 'root_not_directory', 'root_symlink'].includes(rootInspection.exclusions[0].reason)) {
    return {
      schema: 'static-site-importer/fixture-matrix-coverage/v1',
      active: coverageInventoryFromInspection('active', rootInspection, options),
      solved: emptyCoverageInventory('solved', path.join(fixtureRoot, 'solved'), options),
      gate: { status: 'passed', reasons: [] },
    };
  }
  const searchRoots = resolveFixtureSearchRoots(fixtureRoot);
  const selectedIds = new Set(selectedFixtures.map((fixture) => fixture.id));
  const inventories = searchRoots.map((searchRoot) => {
    const inspection = inspectFixtureDirectories(searchRoot, options);
    const corpus = corpusLabelForSearchRoot(searchRoot, fixtureRoot);
    return { corpus, inspection };
  });
  const eligibleCounts = new Map();
  for (const { inspection } of inventories) {
    for (const fixture of inspection.eligible) {
      eligibleCounts.set(fixture.id, (eligibleCounts.get(fixture.id) || 0) + 1);
    }
  }
  const coverageInventories = inventories.map(({ corpus, inspection }) => {
    const crossCorpusDuplicates = inspection.eligible
      .filter((fixture) => eligibleCounts.get(fixture.id) > 1)
      .map((fixture) => coverageRow({ corpus, root: inspection.root, fixture, reason: 'duplicate_id' }));
    const eligible = inspection.eligible.filter((fixture) => eligibleCounts.get(fixture.id) === 1);
    const selected = eligible
      .filter((fixture) => selectedIds.has(fixture.id))
      .map(({ id }) => ({ id, reason: 'selected' }));
    const skipped = [
      ...eligible.filter((fixture) => !selectedIds.has(fixture.id)).map(({ id }) => ({ id, reason: filter ? 'filter_mismatch' : 'omitted' })),
      ...inspection.exclusions.map(({ id, reason }) => ({ id, reason })),
    ].sort((left, right) => left.id.localeCompare(right.id) || left.reason.localeCompare(right.reason));
    return coverageInventoryFromInspection(corpus, inspection, options, { eligible, selected, skipped, crossCorpusDuplicates });
  });
  const active = coverageInventories.find((inventory) => inventory.corpus === 'active') || emptyCoverageInventory('active', fixtureRoot, options);
  const solved = coverageInventories.find((inventory) => inventory.corpus === 'solved') || emptyCoverageInventory('solved', path.join(fixtureRoot, 'solved'), options);
  const invalid = [...coverageInventories.flatMap((inventory) => inventory.malformed), ...coverageInventories.flatMap((inventory) => inventory.duplicates)];
  const omitted = filter ? [] : coverageInventories.flatMap((inventory) => inventory.skipped).filter((fixture) => fixture.reason === 'omitted');
  return {
    schema: 'static-site-importer/fixture-matrix-coverage/v1',
    active,
    solved,
    gate: {
      status: invalid.length || omitted.length ? 'failed' : 'passed',
      reasons: [...invalid, ...omitted].map((row) => row.reason).sort(),
    },
  };
}

function coverageInventoryFromInspection(corpus, inspection, options, overrides = {}) {
  const eligible = overrides.eligible || inspection.eligible;
  return {
    corpus,
    root: inspection.root,
    entrypoint: inspection.entrypoint || options.entrypoint || 'index.html',
    discovered_fixture_ids: inspection.selected_ids,
    eligible_fixture_ids: eligible.map((fixture) => fixture.id),
    selected: overrides.selected || [],
    skipped: overrides.skipped || inspection.exclusions.map(({ id, reason }) => ({ id, reason })),
    malformed: inspection.malformed.map((fixture) => coverageRow({ corpus, root: inspection.root, fixture })),
    duplicates: [...inspection.duplicates.map((fixture) => coverageRow({ corpus, root: inspection.root, fixture })), ...(overrides.crossCorpusDuplicates || [])]
      .sort((left, right) => left.id.localeCompare(right.id) || left.corpus.localeCompare(right.corpus) || left.path.localeCompare(right.path)),
  };
}

function coverageRow({ corpus, root, fixture, reason = fixture.reason }) {
  return {
    id: fixture.id,
    reason,
    corpus,
    root,
    path: fixture.directory,
  };
}

function emptyCoverageInventory(corpus, root, options) {
  return {
    corpus,
    root: path.resolve(root),
    entrypoint: options.entrypoint || 'index.html',
    discovered_fixture_ids: [],
    eligible_fixture_ids: [],
    selected: [],
    skipped: [],
    malformed: [],
    duplicates: [],
  };
}

function detectCorpusLayout(fixtureRoot) {
  const resolvedRoot = path.resolve(fixtureRoot);
  if (!isRealDirectory(resolvedRoot)) {
    return null;
  }
  const websitesRoot = path.join(resolvedRoot, 'websites');
  if (!fs.existsSync(websitesRoot) || !fs.statSync(websitesRoot).isDirectory()) {
    return null;
  }
  const directories = ['websites'];
  const solvedRoot = path.join(resolvedRoot, 'solved');
  if (fs.existsSync(solvedRoot) && fs.statSync(solvedRoot).isDirectory()) {
    directories.push('solved');
  }
  return {
    fixture_root: resolvedRoot,
    fixture_directories: directories,
  };
}

// Classify a fixture. The per-fixture `fixture.json` manifest is the SOLE source
// of truth for `fixture_class` / legacy `class` — there is no heuristic fallback. Resolution order:
//   1. An explicit class injected by tests / the runner / a carried result.
//   2. The fixture's manifest `fixture_class` or legacy `class` (must be a verbatim FIXTURE_CLASSES value).
//   3. `unknown` — emitted with a loud warning naming the fixture.
// A missing manifest or an invalid class value does NOT crash the run: the
// single offending fixture resolves to `unknown` and is flagged.
export function classifyFixture(input = {}) {
  const explicit = normalizeFixtureClass(input.fixture_class || input.class);
  if (explicit && explicit !== 'unknown') {
    return { fixture_class: explicit, signals: ['explicit_metadata'], coverage_status: 'known', warning: null };
  }

  const fixtureName = fixtureLabelFor(input);
  const manifest = input.manifest !== undefined
    ? input.manifest
    : readFixtureManifest(input.directory || input.path || input.fixture_path || input.fixturePath);

  if (!manifest || typeof manifest !== 'object') {
    const warning = `Fixture "${fixtureName}" has no ${FIXTURE_MANIFEST_FILENAME} manifest; classifying as "unknown".`;
    warnFixtureClassification(warning);
    return { fixture_class: 'unknown', signals: ['manifest_missing'], coverage_status: 'missing_manifest', warning };
  }

  const rawClass = manifest.fixture_class ?? manifest.class;
  if (typeof rawClass !== 'string' || !FIXTURE_CLASSES.includes(rawClass)) {
    const warning = `Fixture "${fixtureName}" ${FIXTURE_MANIFEST_FILENAME} has invalid class ${JSON.stringify(rawClass)}; expected one of ${FIXTURE_CLASSES.join(', ')}. Classifying as "unknown".`;
    warnFixtureClassification(warning);
    return { fixture_class: 'unknown', signals: ['manifest_invalid_class'], coverage_status: 'invalid_class', warning };
  }

  return { fixture_class: rawClass, signals: ['manifest'], coverage_status: rawClass === 'unknown' ? 'unknown_class' : 'known', warning: null };
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

export function normalizeManifestCapabilities(value) {
  return normalizeArray(value)
    .map((capability) => String(capability || '').trim().toLowerCase())
    .filter(Boolean)
    .sort();
}

export function normalizeManifestRiskProfile(value) {
  if (value === undefined || value === null || value === '') {
    return 'unknown';
  }
  return String(value).trim().toLowerCase().replace(/[\s_]+/g, '-').replace(/[^a-z0-9/-]+/g, '') || 'unknown';
}

export function normalizeManifestQualityBudgets(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return {};
  }
  return Object.fromEntries(Object.entries(value)
    .map(([key, budget]) => [String(key || '').trim(), normalizeQualityBudgetValue(budget)])
    .filter(([key, budget]) => key && budget !== undefined));
}

function normalizeQualityBudgetValue(value) {
  if (typeof value === 'number' && Number.isFinite(value)) {
    return value;
  }
  if (typeof value === 'boolean' || typeof value === 'string') {
    return value;
  }
  if (!value || typeof value !== 'object' || Array.isArray(value)) {
    return undefined;
  }
  const normalized = Object.fromEntries(Object.entries(value)
    .map(([key, item]) => [String(key || '').trim(), normalizeQualityBudgetValue(item)])
    .filter(([key, item]) => key && item !== undefined));
  return Object.keys(normalized).length ? normalized : undefined;
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
  const fixtureIds = normalizeArray(input.fixture_ids || input.fixtureIds)
    .flatMap((value) => String(value || '').split(','))
    .map((value) => slug(value))
    .filter(Boolean);
  const classValue = normalizeFixtureClass(input.class || input.fixture_class || input.fixtureClass);
  let fixtureClass = '';
  if (classValue && classValue !== 'unknown') {
    fixtureClass = classValue;
  } else if (input.class || input.fixture_class || input.fixtureClass) {
    fixtureClass = 'unknown';
  }
  const tags = normalizeManifestTags(input.tag || input.tags).map((tag) => tag.toLowerCase());
  const capabilities = normalizeManifestCapabilities(input.capability || input.capabilities);
  const riskProfile = input.risk_profile || input.riskProfile ? normalizeManifestRiskProfile(input.risk_profile || input.riskProfile) : '';
  const fixtureCorpus = String(input.fixture_corpus || input.fixtureCorpus || '').trim().toLowerCase();
  const hasComplexity = input.complexity !== undefined && input.complexity !== null && input.complexity !== '';
  const hasMaxComplexity = input.max_complexity !== undefined || input.maxComplexity !== undefined;
  const complexity = hasComplexity ? normalizeManifestComplexity(input.complexity) : null;
  const maxComplexity = hasMaxComplexity ? normalizeManifestComplexity(input.max_complexity ?? input.maxComplexity) : null;
  if (fixtureIds.length === 0 && !fixtureClass && tags.length === 0 && capabilities.length === 0 && !riskProfile && !fixtureCorpus && complexity === null && maxComplexity === null) {
    return null;
  }
  return {
    ...(fixtureIds.length ? { fixture_ids: [...new Set(fixtureIds)].sort() } : {}),
    ...(fixtureClass ? { fixture_class: fixtureClass } : {}),
    ...(tags.length ? { tags } : {}),
    ...(capabilities.length ? { capabilities } : {}),
    ...(riskProfile ? { risk_profile: riskProfile } : {}),
    ...(fixtureCorpus ? { fixture_corpus: fixtureCorpus } : {}),
    ...(complexity !== null ? { complexity } : {}),
    ...(maxComplexity !== null ? { max_complexity: maxComplexity } : {}),
  };
}

function fixtureMatchesFilter(fixture, filter) {
  if (filter.fixture_ids && !filter.fixture_ids.includes(fixture.id)) {
    return false;
  }
  if (filter.fixture_corpus && fixture.fixture_corpus !== filter.fixture_corpus) {
    return false;
  }
  if (filter.fixture_class && fixture.fixture_class !== filter.fixture_class) {
    return false;
  }
  if (filter.tags && filter.tags.length > 0) {
    const fixtureTags = normalizeManifestTags(fixture.tags).map((tag) => tag.toLowerCase());
    if (!filter.tags.every((tag) => fixtureTags.includes(tag))) {
      return false;
    }
  }
  if (filter.capabilities && filter.capabilities.length > 0) {
    const fixtureCapabilities = normalizeManifestCapabilities(fixture.capabilities);
    if (!filter.capabilities.every((capability) => fixtureCapabilities.includes(capability))) {
      return false;
    }
  }
  if (filter.risk_profile && fixture.risk_profile !== filter.risk_profile) {
    return false;
  }
  if (filter.complexity !== undefined && fixture.complexity !== filter.complexity) {
    return false;
  }
  if (filter.max_complexity !== undefined && (!Number.isInteger(fixture.complexity) || fixture.complexity > filter.max_complexity)) {
    return false;
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
  const capabilities = normalizeManifestCapabilities(manifest?.capabilities ?? input.capabilities);
  const riskProfile = normalizeManifestRiskProfile(manifest?.risk_profile ?? manifest?.riskProfile ?? input.risk_profile ?? input.riskProfile);
  const qualityBudgets = normalizeManifestQualityBudgets(manifest?.quality_budgets ?? manifest?.qualityBudgets ?? input.quality_budgets ?? input.qualityBudgets);
  const allowUnprovenDynamicClientAssets = manifest?.allow_unproven_dynamic_client_assets === true || input.allow_unproven_dynamic_client_assets === true;
  const fixtureCorpus = input.fixture_corpus || corpusLabelForSearchRoot(root, root);
  return {
    id,
    label: input.label || input.name || id,
    directory,
    fixture_path: directory,
    fixture_root: root,
    fixture_corpus: fixtureCorpus,
    entrypoint: input.entrypoint || 'index.html',
    fixture_class: taxonomy.fixture_class,
    tags,
    complexity,
    capabilities,
    risk_profile: riskProfile,
    quality_budgets: qualityBudgets,
    allow_unproven_dynamic_client_assets: allowUnprovenDynamicClientAssets,
    taxonomy: {
      ...taxonomy,
      tags,
      complexity,
      capabilities,
      risk_profile: riskProfile,
      quality_budgets: qualityBudgets,
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
    coverage_status: taxonomy.coverage_status || taxonomy.coverageStatus || 'known',
    warning: taxonomy.warning || null,
  };
}

export function fixtureManifestCoverage(fixtures) {
  const rows = normalizeArray(fixtures);
  const unknown = rows.filter((fixture) => normalizeFixtureClass(fixture.fixture_class || fixture.taxonomy?.fixture_class) === 'unknown');
  const missing = rows.filter((fixture) => fixture.taxonomy?.coverage_status === 'missing_manifest');
  const invalid = rows.filter((fixture) => fixture.taxonomy?.coverage_status === 'invalid_class');
  const explicitUnknown = rows.filter((fixture) => fixture.taxonomy?.coverage_status === 'unknown_class');
  return {
    fixture_count: rows.length,
    known_fixture_class_count: rows.length - unknown.length,
    unknown_fixture_class_count: unknown.length,
    missing_manifest_count: missing.length,
    invalid_class_count: invalid.length,
    explicit_unknown_class_count: explicitUnknown.length,
    unknown_fixture_ids: unknown.map((fixture) => fixture.id || fixture.fixture_id).filter(Boolean).sort(),
    gate: {
      status: unknown.length > 0 ? 'warning' : 'passed',
      reason: unknown.length > 0 ? 'Some fixtures have unknown taxonomy; author fixture.json metadata before treating lane coverage as complete.' : 'All discovered fixtures have known fixture_class metadata.',
    },
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
      if (entry.isFile() && [FIXTURE_MANIFEST_FILENAME, GENERATED_ARTIFACT_METADATA_FILENAME].includes(entry.name)) {
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
      files.push({ relative_path: relativePath, absolute_path: fs.realpathSync(entryPath), type: fileType(relativePath), bytes: stat.size });
      if (files.length > maxFiles) {
        throw new Error(`Fixture ${directory} has more than ${maxFiles} files.`);
      }
    }
  };
  visit(directory);
  return files.sort((left, right) => left.relative_path.localeCompare(right.relative_path));
}

// Keep build/source files out of positive artifacts while preserving an
// auditable, stable record of every exclusion.
export function collectFixtureArtifactFiles(directory, options = {}) {
  const files = [];
  const exclusions = [];
  for (const file of collectFixtureFiles(directory, options)) {
    if (isStaticImportPath(file.relative_path)) {
      files.push(file);
      continue;
    }
    exclusions.push({
      schema: 'static-site-importer/fixture-artifact-exclusion/v1',
      source_path: file.absolute_path,
      artifact_path: `website/${file.relative_path}`,
      reason: 'not_static_import_content',
    });
  }
  return { files, exclusions };
}

function isStaticImportPath(filePath) {
  const extension = pathinfoExtension(filePath);
  return STATIC_IMPORT_EXTENSIONS.has(extension);
}

// PHP's PATHINFO_EXTENSION treats `.env` as extension `env`, unlike extname().
export function pathinfoExtension(filePath) {
  const name = String(filePath).replace(/\\/g, '/').split('/').pop() || '';
  const separator = name.lastIndexOf('.');
  return separator < 0 ? '' : name.slice(separator + 1).toLowerCase();
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
