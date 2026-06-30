// Visual-parity recipe step (#538) for the Static Site Importer fixture matrix.
//
// Extracted verbatim from the former `lib/fixture-matrix.mjs` monolith as part
// of the matrix modularization (Refs #242).
//
// PRIMARY PARITY SIGNAL: the deterministic, render-free static style parity gate
// (blocks-engine php-transformer `composer static-parity`) is the primary parity
// signal — same inputs yield a byte-identical 0..1 score plus a per-element /
// per-property diff, with no rasterization, no dimension-sensitivity, and no OOM.
// This full-page pixelmatch step is DEMOTED to optional visual EVIDENCE: it is
// non-gating by default (see findings.mjs `resolveLossAcceptance`) and now also
// captures a bounded viewport by default rather than a full-page screenshot,
// because unbounded full-page capture OOM'd on tall (~6000px) pages. Full-page
// capture remains available per-fixture via an explicit `full_page`/`fullPage`
// opt-in.

import {
  DEFAULT_VISUAL_PARITY_PIXEL_THRESHOLD,
  DEFAULT_VISUAL_PARITY_CANDIDATE_URL,
  DEFAULT_VISUAL_PARITY_SOURCE_BASE_URL,
  DEFAULT_VISUAL_PARITY_VIEWPORT,
  DEFAULT_VISUAL_PARITY_WAIT_FOR,
  VISUAL_PARITY_SOURCE_SUBDIR,
} from '../shared/constants.mjs';
import { objectValue, finiteNumber, isTruthySignal } from '../shared/utils.mjs';

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
// `<fixture-id>/<VISUAL_PARITY_SOURCE_SUBDIR>/...` (see `stageFixtureSource`),
// and that tree is mounted into the sandbox at the WordPress uploads path, so the
// default `source-url` resolves to `<base>/<fixture-id>/source/<entry>` served by
// the SAME in-sandbox WordPress origin as the candidate. The previous default
// pointed at an unstaged path, so source capture hung to the 120s timeout.
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
  const candidateUrl = input.candidateUrl
    || input.candidate_url
    || fixture.candidate_url
    || fixture.candidateUrl
    || options.candidateUrl;
  return {
    command: 'wordpress.visual-compare',
    args: [
      `source-url=${sourceUrl}`,
      `candidate-url=${candidateUrl}`,
      `source-label=${fixture.id ? `${fixture.id}-source` : 'source'}`,
      `candidate-label=${fixture.id ? `${fixture.id}-candidate` : 'candidate'}`,
      `viewport=${options.viewport.width}x${options.viewport.height}`,
      `full-page=${options.fullPage ? 'true' : 'false'}`,
      `wait-for=${options.waitFor}`,
      `threshold=${options.pixelThreshold}`,
    ],
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
    // Demoted to optional evidence: full-page capture is opt-in (default false)
    // so the OOM-prone unbounded screenshot is never the default. Any truthy
    // `full_page`/`fullPage`/`visual_parity_full_page` re-enables it per fixture.
    fullPage: isTruthySignal(input.visualParityFullPage ?? input.visual_parity_full_page ?? input.fullPage),
    waitFor: input.visualParityWaitFor || input.visual_parity_wait_for || input.waitFor || DEFAULT_VISUAL_PARITY_WAIT_FOR,
  };
}
