/**
 * The fixture-matrix's operator, Homeboy, and bench boundary all use this map.
 * Environment names remain the public Homeboy contract; internal projections use
 * camelCase so recipe and gate builders never need to parse environment values.
 */
export const FIXTURE_MATRIX_RUN_FIELDS = Object.freeze({
  fixtureRoot: { env: 'SSI_FIXTURE_MATRIX_FIXTURE_ROOT', required: true, string: true, projections: {} },
  staticSiteImporterPath: { env: 'SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH', required: true, string: true, projections: {} },
  run: { env: 'SSI_FIXTURE_MATRIX_RUN', boolean: true, always: true, projections: {} },
  fixtureIds: { env: 'SSI_FIXTURE_MATRIX_FIXTURE_IDS', list: true, projections: {} },
  fixtureCorpus: { env: 'SSI_FIXTURE_MATRIX_FIXTURE_CORPUS', string: true, projections: {} },
  requireSolvedCandidate: { env: 'SSI_FIXTURE_MATRIX_REQUIRE_SOLVED_CANDIDATE', boolean: true, projections: { recipe: 'requireSolvedCandidate' } },
  blocksEnginePhpTransformerPath: { env: 'SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH', string: true, projections: {} },
  blocksEnginePhpTransformerReference: { env: 'SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_REFERENCE', string: true, projections: {} },
  batchSize: { env: 'SSI_FIXTURE_MATRIX_BATCH_SIZE', integer: { min: 1 }, default: 10, always: true, projections: {} },
  concurrency: { env: 'SSI_FIXTURE_MATRIX_CONCURRENCY', integer: { min: 1, max: 16 }, default: 2, always: true, projections: {} },
  batchInactivityTimeoutMs: { env: 'SSI_FIXTURE_MATRIX_BATCH_INACTIVITY_TIMEOUT_MS', integer: { min: 1 }, projections: {} },
  wordpressVersion: { env: 'SSI_FIXTURE_MATRIX_WORDPRESS_VERSION', string: true, projections: {} },
  wpCodeboxBin: { env: 'SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN', string: true, projections: {} },
  surfaceCoverage: { env: 'SSI_FIXTURE_MATRIX_SURFACE_COVERAGE', integer: { min: 0 }, projections: { recipe: 'surfaceCoverage' } },
  maxExtraSurfaces: { env: 'SSI_FIXTURE_MATRIX_MAX_EXTRA_SURFACES', integer: { min: 0 }, projections: { recipe: 'maxExtraSurfaces' } },
  themeMaterialization: { env: 'SSI_FIXTURE_MATRIX_THEME_MATERIALIZATION', enum: ['block', 'classic'], default: 'block', projections: { recipe: 'themeMaterialization' } },
  editorValidation: { env: 'SSI_FIXTURE_MATRIX_EDITOR_VALIDATION', boolean: true, default: true, always: true, projections: { recipe: 'editorValidation' } },
  visualParity: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY', boolean: true, default: true, always: true, projections: { recipe: 'visualParity' } },
  visualParityViewportWidth: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_VIEWPORT_WIDTH', integer: { min: 1 }, projections: { recipe: 'visualParityViewport.width' } },
  visualParityViewportHeight: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_VIEWPORT_HEIGHT', integer: { min: 1 }, projections: { recipe: 'visualParityViewport.height' } },
  visualParityGate: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE', boolean: true, default: true, always: true, projections: { gate: 'visualParity.gate' } },
  pixelThreshold: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXEL_THRESHOLD', number: true, projections: { recipe: 'pixelThreshold', gate: 'visualParity.threshold' } },
  visualParityAlignment: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_ALIGNMENT', boolean: true, projections: { gate: 'visualParity.alignment' } },
  visualParityMaxVerticalShift: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_VERTICAL_SHIFT', number: true, projections: { gate: 'visualParity.maxVerticalShift' } },
  visualParityMaxHorizontalShift: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_HORIZONTAL_SHIFT', number: true, projections: { gate: 'visualParity.maxHorizontalShift' } },
  visualParityOffsetTolerance: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_OFFSET_TOLERANCE', number: true, projections: { gate: 'visualParity.offsetTolerance' } },
  // Projects into BOTH surfaces: the recipe forwards it to the comparator as
  // pixelmatch's colour distance, and the gate reuses it for host-side alignment
  // scoring. A gate-only projection left the comparator on its own default and
  // made the configured value inert (Refs #1404).
  visualParityPixelmatchThreshold: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXELMATCH_THRESHOLD', number: true, projections: { recipe: 'visualParityPixelmatchThreshold', gate: 'visualParity.pixelmatchThreshold' } },
  maxExplanationElements: { env: 'SSI_FIXTURE_MATRIX_MAX_EXPLANATION_ELEMENTS', integer: { min: 1 }, projections: { recipe: 'maxExplanationElements' } },
  maxExplanationCandidates: { env: 'SSI_FIXTURE_MATRIX_MAX_EXPLANATION_CANDIDATES', integer: { min: 1 }, projections: { recipe: 'maxExplanationCandidates' } },
  explainSelectors: { env: 'SSI_FIXTURE_MATRIX_EXPLAIN_SELECTORS', list: true, projections: { recipe: 'explainSelectors' } },
  liveWpParity: { env: 'SSI_FIXTURE_MATRIX_LIVE_WP_PARITY', boolean: true, projections: { recipe: 'liveWpParity' } },
  minNativeRate: { env: 'SSI_FIXTURE_MATRIX_MIN_NATIVE_RATE', number: true, projections: { gate: 'editorQuality.minNativeRate' } },
  fixtureClass: { env: 'SSI_FIXTURE_MATRIX_CLASS', string: true, projections: {} },
  tag: { env: 'SSI_FIXTURE_MATRIX_TAG', string: true, projections: {} },
  capabilities: { env: 'SSI_FIXTURE_MATRIX_CAPABILITIES', string: true, projections: {} },
  capability: { env: 'SSI_FIXTURE_MATRIX_CAPABILITY', string: true, projections: {} },
  riskProfile: { env: 'SSI_FIXTURE_MATRIX_RISK_PROFILE', string: true, projections: {} },
  complexity: { env: 'SSI_FIXTURE_MATRIX_COMPLEXITY', string: true, projections: {} },
  maxComplexity: { env: 'SSI_FIXTURE_MATRIX_MAX_COMPLEXITY', string: true, projections: {} },
});

export function normalizeFixtureMatrixRunConfig(input = {}) {
  const unknown = Object.keys(input).filter((key) => !Object.hasOwn(FIXTURE_MATRIX_RUN_FIELDS, key));
  if (unknown.length) throw new Error(`Unknown fixture matrix run configuration: ${unknown.join(', ')}`);
  return Object.fromEntries(Object.entries(FIXTURE_MATRIX_RUN_FIELDS).map(([key, field]) => [key, normalizeField(key, input[key], field)]));
}

export function fixtureMatrixRunConfigFromEnv(env = process.env) {
  const settings = settingsBenchEnv(env);
  const input = Object.fromEntries(Object.entries(FIXTURE_MATRIX_RUN_FIELDS).map(([key, field]) => [key, settings[field.env] ?? env[field.env]]));
  return normalizeFixtureMatrixRunConfig(input);
}

export function fixtureMatrixHomeboySettings(config) {
  return Object.fromEntries(Object.entries(FIXTURE_MATRIX_RUN_FIELDS).flatMap(([key, field]) => {
    const value = config[key];
    if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) return [];
    if (!field.always && value === field.default) return [];
    return [[field.env, field.boolean ? (value ? '1' : '0') : Array.isArray(value) ? value.join(',') : String(value)]];
  }));
}

export function fixtureMatrixBenchOptions(config) {
  return { ...config, fixtureIds: config.fixtureIds.join(',') };
}

export function fixtureMatrixRecipeInput(config) {
  return projectRunConfig(config, 'recipe');
}

export function fixtureMatrixGateConfig(config) {
  return projectRunConfig(config, 'gate');
}

function projectRunConfig(config, projection) {
  return Object.entries(FIXTURE_MATRIX_RUN_FIELDS).reduce((output, [key, field]) => {
    const target = field.projections[projection];
    if (!target) return output;
    const segments = target.split('.');
    const leaf = segments.pop();
    const parent = segments.reduce((value, segment) => (value[segment] ||= {}), output);
    parent[leaf] = config[key];
    return output;
  }, {});
}

function normalizeField(key, value, field) {
  if (value === undefined || value === null || value === '') return field.list ? [] : field.default;
  if (field.boolean) return booleanValue(key, value);
  if (field.list) return [...new Set((Array.isArray(value) ? value : String(value).split(',')).map((item) => String(item).trim()).filter(Boolean))];
  if (field.enum) {
    const normalized = String(value);
    if (!field.enum.includes(normalized)) throw new Error(`Fixture matrix ${key} must be one of: ${field.enum.join(', ')}.`);
    return normalized;
  }
  if (field.string) return String(value);
  const number = Number(value);
  if (!Number.isFinite(number)) throw new Error(`Fixture matrix ${key} must be a finite number.`);
  if (field.integer && !Number.isInteger(number)) throw new Error(`Fixture matrix ${key} must be an integer.`);
  if (field.integer?.min !== undefined && number < field.integer.min) throw new Error(`Fixture matrix ${key} must be at least ${field.integer.min}.`);
  if (field.integer?.max !== undefined && number > field.integer.max) throw new Error(`Fixture matrix ${key} must be at most ${field.integer.max}.`);
  return number;
}

function booleanValue(key, value) {
  if (value === true || value === '1' || value === 'true') return true;
  if (value === false || value === '0' || value === 'false' || value === 'no' || value === 'off') return false;
  throw new Error(`Fixture matrix ${key} must be a boolean.`);
}

function settingsBenchEnv(env) {
  try {
    const settings = JSON.parse(env.HOMEBOY_SETTINGS_JSON || '{}');
    return settings && typeof settings.bench_env === 'object' && !Array.isArray(settings.bench_env) ? settings.bench_env : {};
  } catch {
    return {};
  }
}
