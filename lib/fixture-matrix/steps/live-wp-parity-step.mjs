// Live-WP parity capture step for the Static Site Importer fixture matrix.
//
// Companion to the render-free static-parity gate (blocks-engine php-transformer
// `composer static-parity`), which is the PRIMARY parity signal but never
// exercises WordPress's own block rendering + global-styles layer. This OPTIONAL
// step (off by default) captures the imported candidate's RENDERED DOM HTML from a
// real WordPress render so the same deterministic comparator can be run against
// real WP output via blocks-engine `composer live-wp-parity`.
//
// It composes the EXISTING `wordpress.capture-html` command (Playwright
// `page.content()` — the serialized rendered DOM, NOT a screenshot) so there is no
// rasterization and no OOM risk. External requests are blocked (`network-policy=block`)
// so the captured DOM is self-contained and deterministic: same import + same
// render -> byte-identical snapshot.html -> byte-identical comparator report.
//
// The captured `files/browser/snapshot.html` artifact is fed host-side to the
// blocks-engine live-wp-parity runner by `runLiveWpParity`
// (see ../collectors/live-wp-parity.mjs), which surfaces the live-WP parity score
// and per-property diff alongside the render-free proxy score.

import {
  DEFAULT_VISUAL_PARITY_CANDIDATE_URL,
  DEFAULT_VISUAL_PARITY_WAIT_FOR,
} from '../shared/constants.mjs';
import { isTruthySignal } from '../shared/utils.mjs';

// Compose `wordpress.capture-html` for a fixture's imported candidate front page.
// The candidate URL resolution mirrors the visual-parity step: it defaults to `/`,
// which resolves to THIS fixture's imported front page (`page_on_front`) at capture
// time because each import step activates with `activate=true` and the recipe
// interleaves import -> capture per fixture. Per-fixture overrides are honored.
export function liveWpParityCaptureStep(input = {}) {
  const fixture = input.fixture || {};
  const options = normalizeLiveWpParityRecipeOptions(input);
  const candidateUrl = input.candidateUrl
    || input.candidate_url
    || fixture.candidate_url
    || fixture.candidateUrl
    || options.candidateUrl;
  return {
    command: 'wordpress.capture-html',
    args: [
      `url=${candidateUrl}`,
      // DOM HTML only — deterministic, no screenshot, no rasterization.
      'capture=html',
      // Block external requests so the captured DOM is self-contained and stable.
      'network-policy=block',
      `wait-for=${options.waitFor}`,
    ],
  };
}

export function normalizeLiveWpParityRecipeOptions(input = {}) {
  return {
    candidateUrl: input.liveWpParityCandidateUrl
      || input.live_wp_parity_candidate_url
      || input.candidateUrl
      || DEFAULT_VISUAL_PARITY_CANDIDATE_URL,
    waitFor: input.liveWpParityWaitFor
      || input.live_wp_parity_wait_for
      || input.waitFor
      || DEFAULT_VISUAL_PARITY_WAIT_FOR,
  };
}

// The live-WP capture is OPT-IN: the render-free static-parity gate remains the
// primary, always-on signal, so the heavier real-render capture only runs when a
// caller explicitly enables it. Default false.
export function liveWpParityEnabled(input = {}) {
  return isTruthySignal(input.liveWpParity ?? input.live_wp_parity);
}
