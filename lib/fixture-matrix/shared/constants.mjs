// Shared constants for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242). Pure data only — no behavior.

export const FIXTURE_MATRIX_SCHEMA = 'static-site-importer/fixture-matrix/v1';
export const FIXTURE_MATRIX_RESULT_SCHEMA = 'static-site-importer/fixture-matrix-result/v1';
export const WEBSITE_ARTIFACT_SCHEMA = 'blocks-engine/php-transformer/site-artifact/v1';

export const DEFAULT_ENTRYPOINT = 'website/index.html';
export const DEFAULT_IMPORTER_SLUG = 'static-site-importer';

// Editor browser evidence wiring. The default recipe step emits
// `wordpress.editor-validate-blocks`, and the fixture-matrix rig declares that
// runner tool capability before Homeboy dispatches Lab evidence runs.
//
// The legacy `wordpress.editor-canvas-probe` selector shape (`.block-editor-warning`
// / `is-invalid` DOM warnings) is still parsed for backward compatibility with
// older artifacts.
export const EDITOR_BLOCK_INVALID_KIND = 'editor_block_invalid';
export const EDITOR_INVALID_BLOCK_SELECTOR_GROUP = 'editor_block_invalid';
export const EDITOR_INVALID_BLOCK_SELECTORS = [
  '.block-editor-warning',
  '.block-editor-block-list__block.is-invalid',
  '.wp-block[data-block].is-invalid',
];
// Schema/method/provider for newer `wp.blocks.validateBlock` artifacts. The
// collector keys off these values when a runner emits that result shape.
export const EDITOR_OPEN_COMMAND = 'wordpress.editor-open';
export const EDITOR_VALIDATE_BLOCKS_COMMAND = 'wordpress.editor-validate-blocks';
export const EDITOR_VALIDATE_BLOCKS_SCHEMA = 'wp-codebox/editor-validate-blocks/v1';
export const EDITOR_VALIDATION_METHOD = 'wp.blocks.validateBlock';
export const EDITOR_VALIDATION_PROVIDER = 'wordpress-block-editor';
// The per-fixture recipe interleaves import then editor validation. `front-page`
// resolves at runtime to the just-imported `page_on_front`, so validation targets
// real imported content even when the imported post ID is not known at recipe
// generation time.
export const DEFAULT_EDITOR_VALIDATION_TARGET = 'front-page';
// Retained for callers that still forward an explicit editor post type; the
// default target no longer keys off it.
export const DEFAULT_EDITOR_VALIDATION_POST_TYPE = 'page';
// Legacy empty-post canvas-probe URL. Retained only so callers that still pass
// an explicit editor URL keep working; the recipe no longer defaults to it.
export const DEFAULT_EDITOR_VALIDATION_URL = '/wp-admin/post-new.php';
export const EDITOR_BLOCK_INVALID_DEFAULT_DETAIL = 'This block contains unexpected or invalid content';

// Pixel visual-parity wiring. After import, the same WP Codebox sandbox renders
// the fixture's original static source vs the imported WordPress output via the
// existing `wordpress.visual-compare` recipe command (the same command the
// reusable `runVisualParityWorkload` helper composes in homeboy-extensions). The
// command emits `source.png`/`candidate.png`/`diff.png` plus
// `mismatch_pixels`/`total_pixels`, which `collectVisualParityDiagnostics` reads
// back out. No new wp-codebox capability is introduced. Capture is on by
// default; gating on mismatch-over-threshold is opt-in (pixel diffs can be
// flaky) and is expressed as the conditional `visual_parity_mismatch` loss class.
export const VISUAL_PARITY_MISMATCH_KIND = 'visual_parity_mismatch';

// Editor-quality metrics wiring. The matrix already carries the transformer's
// block-composition breakdown (block-type counts / `detectBlockTypes` output) on
// each fixture's import artifact. From that generic, per-block-type data we score
// how "native and editable" an import is — without any per-fixture knowledge:
//   native_conversion_rate = native core/Automattic blocks / total blocks
//   core_html_fallback_ratio = core/html blocks / total blocks
//   editor_invalid_count = reuse of the #537 `editor_block_invalid` findings
// A block is "native" when it lives in a core or known Automattic namespace
// (core/*, jetpack/*, woocommerce/*, automattic/*, a8c/*) and is not one of the
// non-native fallback wrappers (core/html, core/freeform). This list is the only
// generic basis used; nothing keys off a fixture id or class.
export const NATIVE_BLOCK_NAMESPACES = ['core', 'jetpack', 'woocommerce', 'automattic', 'a8c'];
export const CORE_HTML_BLOCK_NAME = 'core/html';
export const NON_NATIVE_FALLBACK_BLOCK_NAMES = new Set([CORE_HTML_BLOCK_NAME, 'core/freeform']);
// Opt-in native-conversion gate. Like the visual-parity gate, scoring is always
// captured but gating is off by default — it only fires when the run passes a
// positive `--min-native-rate`/`minNativeRate` threshold.
export const LOW_NATIVE_CONVERSION_KIND = 'native_conversion_rate_below_min';

export const DEFAULT_VISUAL_PARITY_PIXEL_THRESHOLD = 0;
// Candidate (imported WordPress output) URL. The per-fixture import step runs
// with activate=true, which sets `show_on_front=page` + `page_on_front` to the
// imported front page (see Static_Site_Importer_Theme_Generator::front_page_id).
// Because the recipe interleaves [import, editor-validate, visual-compare] per
// fixture, `/` resolves to THIS fixture's imported front page at the moment the
// visual-compare runs — the real imported page, not an unrelated WP homepage. A
// fixture can still override `candidate_url`/`candidate-url` to target a specific
// imported permalink (e.g. `/?page_id=<id>` or a pretty permalink).
export const DEFAULT_VISUAL_PARITY_CANDIDATE_URL = '/';
// Fallback base path for the fixture's ORIGINAL static source. Recipe generation
// normally overrides this with a file:// URL rooted at the staged artifact
// directory so source capture does not depend on the WordPress preview proxy.
export const DEFAULT_VISUAL_PARITY_SOURCE_BASE_URL = '/wp-content/uploads/static-site-importer-fixture-matrix';
// Subdirectory (under each fixture's artifacts dir) holding the staged raw source
// site. Kept separate from the per-fixture `artifact.json` import payload so the
// served source tree mirrors the fixture's own relative asset paths exactly.
export const VISUAL_PARITY_SOURCE_SUBDIR = 'source';
export const DEFAULT_VISUAL_PARITY_VIEWPORT = { width: 1280, height: 1600 };
export const DEFAULT_VISUAL_PARITY_WAIT_FOR = 'domcontentloaded';
// A finding only gates when it carries an explicit opt-in gate signal; absent
// that, a mismatch is captured but non-gating (capture-only default).
export const VISUAL_PARITY_GATE_SIGNAL_KEYS = ['gate', 'visual_parity_gate', 'visualParityGate'];

export const DEFAULT_FINDING_GROUPS = {
  // Low native-conversion-rate gate findings route to the transformer's
  // native-conversion bucket. Listed first so the message ("native conversion
  // rate ... below ... minimum") classifies here rather than matching the
  // generic `native conversion` acceptable-loss text downstream.
  low_native_conversion: {
    patterns: [/native_conversion_rate_below_min/i, /native conversion rate .* below/i, /below the .* native (?:conversion )?(?:rate )?minimum/i],
    candidate_repo: 'blocks-engine',
    repair_mode: 'native-conversion-parity',
  },
  // Editor-side block invalidity routes to the Blocks Engine feature/visual
  // parity bucket and must gate. Listed first so the `editor_block_invalid`
  // kind classifies here instead of the structural PHP `invalid_block_content`
  // group, even though both mention "invalid content".
  editor_block_invalid: {
    patterns: [/editor_block_invalid/i, /editor block validation/i, /block-editor-warning/i, /this block contains unexpected or invalid content/i],
    candidate_repo: 'blocks-engine',
    repair_mode: 'editor-block-validation-parity',
  },
  // Pixel visual-parity mismatches route to a dedicated visual-parity repair
  // bucket. Listed early so the `visual_parity_mismatch` kind classifies here
  // rather than falling into a generic group.
  visual_parity_mismatch: {
    patterns: [/visual_parity_mismatch/i, /visual parity/i, /pixel (?:diff|mismatch|parity)/i],
    candidate_repo: 'blocks-engine',
    repair_mode: 'visual-parity',
  },
  button_style_loss: {
    patterns: [/default gray button/i, /button.*gray/i, /button.*style/i],
    candidate_repo: 'blocks-engine',
    repair_mode: 'transformer-style-parity',
  },
  broken_svg: {
    patterns: [/broken svg/i, /svg.*broken/i, /svg.*missing/i],
    candidate_repo: 'blocks-engine',
    repair_mode: 'svg-transformer-parity',
  },
  dropped_images: {
    patterns: [/dropped image/i, /missing image/i, /image.*missing/i, /asset.*missing/i],
    candidate_repo: 'static-site-importer',
    repair_mode: 'asset-materialization',
  },
  invalid_block_content: {
    patterns: [/unexpected or invalid content/i, /invalid block/i, /block validation/i],
    candidate_repo: 'blocks-engine',
    repair_mode: 'block-validation-parity',
  },
  runtime_target_gap: {
    patterns: [/runtime_dependency_target_missing/i, /html_canvas_runtime_fallback/i, /canvas/i, /animation/i, /script target/i],
    candidate_repo: 'blocks-engine',
    repair_mode: 'runtime-dom-target-parity',
  },
};

export const ACCEPTABLE_LOSS_CLASSES = new Set([
  'native_conversion',
  'editable_approximation',
  'preserved_runtime_island',
  // Visual-parity mismatches are capture-only (acceptable) by default; they only
  // become unacceptable when an explicit gate signal is present. See
  // `resolveLossAcceptance`. This mirrors the conditional `preserved_runtime_island`.
  'visual_parity_mismatch',
]);

export const UNACCEPTABLE_LOSS_CLASSES = new Set([
  'unsupported_loss',
  'importer_materialization_bug',
  'invalid_block_output',
  'invalid_block_content',
  'editor_block_invalid',
  'low_native_conversion',
  'missing_asset',
  'missing_output',
  'fixture_not_run',
  'fixture_failed',
]);

// Loss classes whose acceptability is conditional rather than automatic.
// `preserved_runtime_island` only earns an acceptable verdict when the finding
// carries an explicit signal that the interactive runtime/behavior was actually
// carried or mapped into the WordPress site. "Markup preserved, behavior dead"
// is a feature-parity failure, not an acceptable loss.
export const RUNTIME_CARRIED_SIGNAL_KEYS = [
  'runtime_carried',
  'runtimeCarried',
  'runtime_mapped',
  'runtimeMapped',
  'runtime_mapping',
  'runtimeMapping',
];

// Canonical fixture-class vocabulary. A per-fixture `fixture.json` manifest is the
// SOLE source of truth for a fixture's class — see the manifest schema below. The
// manifest's `class` MUST be one of these values verbatim; anything else (or a
// missing manifest) resolves to `unknown` with a loud warning rather than a
// guess. Keep this vocabulary stable: fixtures (owned by blocks-engine) author
// manifests against exactly these strings.
export const FIXTURE_CLASSES = [
  'marketing/static',
  'docs/blog',
  'ecommerce/catalog',
  'app/dashboard',
  'canvas/webgl/audio/runtime-heavy',
  'unknown',
];

// Per-fixture manifest schema (`<fixture-dir>/fixture.json`):
//
//   {
//     "class": "marketing/static",            // REQUIRED. One of FIXTURE_CLASSES, verbatim.
//     "tags": ["restaurant", "has-form"],     // optional. Free-form string array for lane/tag querying.
//     "complexity": 1                         // optional. Integer 1-5.
//   }
//
// The manifest is authored in each fixture directory and owned by blocks-engine
// (the corpus repo). The fixture matrix reads it at load time; class resolution
// is manifest-only (no heuristic fallback). `tags` and `complexity` are carried
// through onto each fixture, the per-fixture result, and the result summary so
// runs can be filtered/queried by lane (class) and tag.
export const FIXTURE_MANIFEST_FILENAME = 'fixture.json';
export const FIXTURE_COMPLEXITY_MIN = 1;
export const FIXTURE_COMPLEXITY_MAX = 5;
