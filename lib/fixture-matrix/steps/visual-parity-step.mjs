// Visual-parity recipe step (#538) for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).
//
// PRIMARY PARITY SIGNAL: fixture-matrix visual parity is full-page by default so
// below-the-fold regressions are visible and, in the dev loop, gating.
/**
 * Internal dependencies
 */
import {
  DEFAULT_VISUAL_PARITY_PIXEL_THRESHOLD,
  DEFAULT_VISUAL_PARITY_CANDIDATE_URL,
  DEFAULT_VISUAL_PARITY_SOURCE_BASE_URL,
  DEFAULT_VISUAL_PARITY_VIEWPORT,
  DEFAULT_VISUAL_PARITY_WAIT_FOR,
  DEFAULT_VISUAL_PARITY_SETTLE_DURATION_MS,
  VISUAL_PARITY_SOURCE_SUBDIR,
} from '../shared/constants.mjs';
import { objectValue, finiteNumber } from '../shared/utils.mjs';
import { fixtureStepMetadata, resolveFixtureCandidateUrl } from './shared.mjs';

// Compose the existing `wordpress.visual-compare` recipe command into a
// per-fixture visual-parity step. This is the same command the reusable
// `runVisualParityWorkload` helper composes in homeboy-extensions; the matrix
// emits it inline alongside the import/editor steps rather than spinning up a
// separate sandbox. It renders the fixture's static source vs the imported
// WordPress candidate and writes `source.png`/`candidate.png`/`diff.png` plus
// the `mismatch_pixels`/`total_pixels` comparison that
// `collectVisualParityDiagnostics` reads back out.
//
// SOURCE URL: the raw fixture source is staged into the matrix artifacts tree at
// `<fixture-id>/<VISUAL_PARITY_SOURCE_SUBDIR>/...` (see `stageFixtureSource`). By
// default the recipe points `source-url` at that staged tree with a file:// URL so
// original static HTML/CSS/JS/images are captured directly, without routing source
// navigation through the WordPress preview proxy. The candidate still renders from
// WordPress, so this remains a real source-vs-imported-output visual comparison.
//
// CANDIDATE URL: defaults to `/`, which (because each fixture's import step runs
// with activate=true → `page_on_front` set, and the recipe interleaves import →
// visual-compare per fixture) resolves to THIS fixture's imported front page at
// capture time — the real imported WordPress output. Both URLs accept per-fixture
// overrides (`source_url`/`candidate_url` on the fixture, or `sourceUrl`/
// `candidateUrl` on the step input) to target a specific staged page or imported
// permalink.
export function visualParityCompareStep(input = {}) {
  const fixture = input.fixture || {};
  const options = normalizeVisualParityRecipeOptions(input);
  const entrypoint = options.sourceEntry || fixture.entrypoint || 'index.html';
  const sourceEntry = `${VISUAL_PARITY_SOURCE_SUBDIR}/${String(entrypoint).replace(/^\/+/, '')}`;
  const sourceUrl = input.sourceUrl
    || input.source_url
    || fixture.source_url
    || fixture.sourceUrl
    || `${options.sourceBaseUrl.replace(/\/+$/, '')}/${fixture.id || 'fixture'}/${sourceEntry}`;
  const candidateUrl = resolveFixtureCandidateUrl(input, fixture, options.candidateUrl);
  const matrix = {
    comparisons: [
      {
        name: fixture.id || 'fixture',
        sourceUrl,
        candidateUrl,
        sourceLabel: fixture.id ? `${fixture.id}-source` : 'source',
        candidateLabel: fixture.id ? `${fixture.id}-candidate` : 'candidate',
        viewport: `${options.viewport.width}x${options.viewport.height}`,
        fullPage: options.fullPage,
        waitFor: options.waitFor,
        durationMs: options.duration,
        blockExternalRequests: options.blockExternalRequests,
        threshold: options.pixelThreshold,
      },
    ],
  };
  return {
    command: 'wordpress.visual-compare',
    allowFailure: true,
    args: [
      `matrix-json=${JSON.stringify(matrix)}`,
    ],
    metadata: fixtureStepMetadata(fixture, 'visual', {
      source_url: sourceUrl,
      candidate_url: candidateUrl,
    }),
  };
}

export function normalizeVisualParityRecipeOptions(input = {}) {
  const viewport = objectValue(input.visualParityViewport || input.visual_parity_viewport || input.viewport);
  return {
    pixelThreshold: finiteNumber(input.pixelThreshold ?? input.pixel_threshold ?? input.visualParityPixelThreshold ?? input.visual_parity_pixel_threshold, DEFAULT_VISUAL_PARITY_PIXEL_THRESHOLD),
    candidateUrl: input.visualParityCandidateUrl || input.visual_parity_candidate_url || input.candidateUrl || DEFAULT_VISUAL_PARITY_CANDIDATE_URL,
    sourceBaseUrl: input.visualParitySourceBaseUrl || input.visual_parity_source_base_url || input.sourceBaseUrl || DEFAULT_VISUAL_PARITY_SOURCE_BASE_URL,
    sourceEntry: input.visualParitySourceEntry || input.visual_parity_source_entry || input.sourceEntry || '',
    viewport: {
      width: finiteNumber(viewport.width, DEFAULT_VISUAL_PARITY_VIEWPORT.width),
      height: finiteNumber(viewport.height, DEFAULT_VISUAL_PARITY_VIEWPORT.height),
    },
    fullPage: !isFalseySignal(input.visualParityFullPage ?? input.visual_parity_full_page ?? input.fullPage ?? input.full_page ?? true),
    waitFor: input.visualParityWaitFor || input.visual_parity_wait_for || input.waitFor || DEFAULT_VISUAL_PARITY_WAIT_FOR,
    duration: normalizeDuration(input.visualParityDurationMs ?? input.visual_parity_duration_ms ?? input.durationMs ?? input.duration_ms ?? input.duration, DEFAULT_VISUAL_PARITY_SETTLE_DURATION_MS),
    blockExternalRequests: !isFalseySignal(input.visualParityBlockExternalRequests ?? input.visual_parity_block_external_requests ?? input.blockExternalRequests ?? input.block_external_requests),
  };
}

function normalizeDuration(value, fallbackMs) {
  if (typeof value === 'string' && /^\d+(?:\.\d+)?(?:ms|s|m)$/i.test(value.trim())) {
    return value.trim();
  }
  const milliseconds = finiteNumber(value, fallbackMs);
  return `${milliseconds}ms`;
}

function isFalseySignal(value) {
  if (value === undefined || value === null) {
    return false;
  }
  if (typeof value === 'string') {
    return ['', 'false', '0', 'no', 'none', 'null', 'undefined'].includes(value.trim().toLowerCase());
  }
  if (typeof value === 'number') {
    return !Number.isFinite(value) || value === 0;
  }
  return value === false;
}
