/**
 * The fixture-matrix's operator, Homeboy, and bench boundary all use this map.
 * Environment names remain the public Homeboy contract; internal projections use
 * camelCase so recipe and gate builders never need to parse environment values.
 */
export const FIXTURE_MATRIX_RUN_FIELDS = Object.freeze({
  fixtureRoot: { env: 'SSI_FIXTURE_MATRIX_FIXTURE_ROOT', required: true, string: true },
  staticSiteImporterPath: { env: 'SSI_FIXTURE_MATRIX_STATIC_SITE_IMPORTER_PATH', required: true, string: true },
  run: { env: 'SSI_FIXTURE_MATRIX_RUN', boolean: true, always: true },
  fixtureIds: { env: 'SSI_FIXTURE_MATRIX_FIXTURE_IDS', list: true },
  fixtureCorpus: { env: 'SSI_FIXTURE_MATRIX_FIXTURE_CORPUS', string: true },
  requireSolvedCandidate: { env: 'SSI_FIXTURE_MATRIX_REQUIRE_SOLVED_CANDIDATE', boolean: true },
  blocksEnginePhpTransformerPath: { env: 'SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_PATH', string: true },
  blocksEnginePhpTransformerReference: { env: 'SSI_FIXTURE_MATRIX_BLOCKS_ENGINE_PHP_TRANSFORMER_REFERENCE', string: true },
  batchSize: { env: 'SSI_FIXTURE_MATRIX_BATCH_SIZE', integer: { min: 1 }, default: 10 },
  concurrency: { env: 'SSI_FIXTURE_MATRIX_CONCURRENCY', integer: { min: 1, max: 16 }, default: 2 },
  batchInactivityTimeoutMs: { env: 'SSI_FIXTURE_MATRIX_BATCH_INACTIVITY_TIMEOUT_MS', integer: { min: 1 } },
  wordpressVersion: { env: 'SSI_FIXTURE_MATRIX_WORDPRESS_VERSION', string: true },
  wpCodeboxBin: { env: 'SSI_FIXTURE_MATRIX_WP_CODEBOX_BIN', string: true },
  surfaceCoverage: { env: 'SSI_FIXTURE_MATRIX_SURFACE_COVERAGE', integer: { min: 0 } },
  maxExtraSurfaces: { env: 'SSI_FIXTURE_MATRIX_MAX_EXTRA_SURFACES', integer: { min: 0 } },
  editorValidation: { env: 'SSI_FIXTURE_MATRIX_EDITOR_VALIDATION', boolean: true, default: true },
  visualParity: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY', boolean: true, default: true },
  visualParityGate: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_GATE', boolean: true, default: true, always: true },
  pixelThreshold: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXEL_THRESHOLD', number: true },
  visualParityAlignment: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_ALIGNMENT', boolean: true },
  visualParityMaxVerticalShift: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_VERTICAL_SHIFT', number: true },
  visualParityMaxHorizontalShift: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_MAX_HORIZONTAL_SHIFT', number: true },
  visualParityOffsetTolerance: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_OFFSET_TOLERANCE', number: true },
  visualParityPixelmatchThreshold: { env: 'SSI_FIXTURE_MATRIX_VISUAL_PARITY_PIXELMATCH_THRESHOLD', number: true },
  maxExplanationElements: { env: 'SSI_FIXTURE_MATRIX_MAX_EXPLANATION_ELEMENTS', integer: { min: 1 } },
  maxExplanationCandidates: { env: 'SSI_FIXTURE_MATRIX_MAX_EXPLANATION_CANDIDATES', integer: { min: 1 } },
  explainSelectors: { env: 'SSI_FIXTURE_MATRIX_EXPLAIN_SELECTORS', list: true },
  liveWpParity: { env: 'SSI_FIXTURE_MATRIX_LIVE_WP_PARITY', boolean: true },
  minNativeRate: { env: 'SSI_FIXTURE_MATRIX_MIN_NATIVE_RATE', number: true },
  fixtureClass: { env: 'SSI_FIXTURE_MATRIX_CLASS', string: true },
  tag: { env: 'SSI_FIXTURE_MATRIX_TAG', string: true },
  capabilities: { env: 'SSI_FIXTURE_MATRIX_CAPABILITIES', string: true },
  capability: { env: 'SSI_FIXTURE_MATRIX_CAPABILITY', string: true },
  riskProfile: { env: 'SSI_FIXTURE_MATRIX_RISK_PROFILE', string: true },
  complexity: { env: 'SSI_FIXTURE_MATRIX_COMPLEXITY', string: true },
  maxComplexity: { env: 'SSI_FIXTURE_MATRIX_MAX_COMPLEXITY', string: true },
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
  return {
    surfaceCoverage: config.surfaceCoverage,
    maxExtraSurfaces: config.maxExtraSurfaces,
    editorValidation: config.editorValidation,
    visualParity: config.visualParity,
    pixelThreshold: config.pixelThreshold,
    maxExplanationElements: config.maxExplanationElements,
    maxExplanationCandidates: config.maxExplanationCandidates,
    explainSelectors: config.explainSelectors,
    liveWpParity: config.liveWpParity,
  };
}

export function fixtureMatrixGateConfig(config) {
  return {
    visualParity: {
      threshold: config.pixelThreshold,
      gate: config.visualParityGate,
      alignment: config.visualParityAlignment,
      maxVerticalShift: config.visualParityMaxVerticalShift,
      maxHorizontalShift: config.visualParityMaxHorizontalShift,
      offsetTolerance: config.visualParityOffsetTolerance,
      pixelmatchThreshold: config.visualParityPixelmatchThreshold,
    },
    editorQuality: { minNativeRate: config.minNativeRate },
  };
}

function normalizeField(key, value, field) {
  if (value === undefined || value === null || value === '') return field.list ? [] : field.default;
  if (field.boolean) return booleanValue(key, value);
  if (field.list) return [...new Set((Array.isArray(value) ? value : String(value).split(',')).map((item) => String(item).trim()).filter(Boolean))];
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
